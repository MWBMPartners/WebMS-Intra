/**
 * -----------------------------------------------------------------------------
 * File:        tools/page-headers-wrap-selftest.mjs
 * Description: Draws EVERY page header the portal has — the row holding a
 *              page's <h1> and its buttons — in a real browser at 320 and
 *              390px, with the portal's real stylesheet, and fails if any of
 *              them is wider than the screen (#848).
 * Package:     Portal\Tools
 * Author:      MWBM Partners Ltd (t/a MWservices)
 * Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 * Version:     1.0.0
 * Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/848
 * -----------------------------------------------------------------------------
 *
 * WHY THIS EXISTS
 *   A page header is a flex row, and a flex row does not wrap unless told to,
 *   so on a phone a title beside its buttons ran off the screen and the whole
 *   page scrolled sideways (#833 found four pages; #848 counted the rest).
 *   portal.css now holds ONE rule that lets every such row wrap below 768px.
 *   tools/audit-checks/check_page_headers_wrap.py keeps every header in a
 *   shape that rule reaches — but a check that reads markup cannot tell
 *   whether a header FITS. This draws them and measures.
 *
 * HOW, AND WHY NOT BY VISITING EVERY PAGE
 *   Most of these headers sit on pages that need a signed-in administrator, a
 *   database and a particular record (an event, a claim, a group) before they
 *   draw at all; standing that up for some 170 pages is a much larger job than
 *   the fault. So the headers are taken from the page SOURCE by the audit
 *   check (`--headers-json` — ONE finder, so this test and the check cannot
 *   disagree about which headers exist), and drawn inside the portal's own
 *   page frame (`<main class="portal-main"><div class="container">`) with the
 *   real Bootstrap, Font Awesome, brand fonts and portal.css, in the order
 *   header.php loads them.
 *
 *   In that markup every printed PHP value is the words "Sample text", and
 *   BOTH sides of every `if`/`else` are drawn. That can only make a header
 *   WIDER than a real one — except where a real title or label is longer than
 *   two words — so a header that fits here fits on the real page.
 *
 * WHAT IT CANNOT SHOW, said plainly
 *   * A real title or button label longer than "Sample text", or a long word
 *     with nowhere to break (a member's e-mail address in a title). A single
 *     unbreakable word wider than a phone is not something wrapping can fix.
 *   * A header printed by PHP as a string or built by JavaScript — the audit
 *     check cannot find those, so this cannot draw them.
 *   * The rest of each page. A page can still be wider than a phone for some
 *     other reason; tools/top-bar-selftest.mjs measures four whole pages, and
 *     tools/layout-audit.mjs any page it is pointed at.
 *   * How it LOOKS. That needs an eye; nothing here judges it.
 *
 * WHAT IT NEEDS
 *   node with a global WebSocket, python3 (to run the audit check), a
 *   Chromium-family browser (CHROME_PATH, Edge, Chrome or Chromium), and the
 *   internet: Bootstrap and Font Awesome come from the same addresses the
 *   portal loads them from, with the same integrity hashes. It REFUSES (exit
 *   2) without any of them, and also when either stylesheet did not apply —
 *   measuring headers drawn without Bootstrap would measure nothing.
 *
 * FOR PLANTING A FAULT
 *   PAGE_HEADERS_SELFTEST_ASSETS may name another copy of
 *   web/public_html/assets to serve instead of this repository's — that is
 *   how the rule is proved necessary (take it out of a copy of portal.css and
 *   this must go red) without editing the working tree.
 *
 * Usage:  node tools/page-headers-wrap-selftest.mjs
 *         exit 0 every header fits; 1 one does not; 2 refused.
 */

import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

/* 🌐 Any Chromium-family browser; the same list as csrf-carriers-selftest.mjs,
      for the same reason (GitHub's Linux runners have no Edge). */
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

/* ⚠️ `fileURLToPath`, never `new URL(...).pathname`: this repository's path
      holds spaces and an `&`, which a URL percent-encodes. */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ASSETS = process.env.PAGE_HEADERS_SELFTEST_ASSETS
    ?? path.join(ROOT, 'web', 'public_html', 'assets');
const CHECK = path.join(ROOT, 'tools', 'audit-checks', 'check_page_headers_wrap.py');
const ASSET_PHP = path.join(ROOT, 'web', '_core', 'Asset.php');

/* 📐 320 is the narrowest phone still in use; 390 a current iPhone. */
const WIDTHS = [320, 390];

/* The stylesheet addresses and integrity hashes are READ from Asset.php, so
   this test loads exactly what a visitor's browser loads and cannot drift
   from it. They are built by concatenation there, so they are taken out by
   asking PHP's own reflection rather than by searching the text. */
function cdnSheets() {
    const code = 'require $argv[1]; $r = new ReflectionClass(\\Portal\\Core\\Asset::class);'
        + ' echo json_encode(['
        + ' [$r->getConstant("CDN_BOOTSTRAP_CSS"), $r->getConstant("BOOTSTRAP_CSS_INTEGRITY")],'
        + ' [$r->getConstant("CDN_FONTAWESOME_CSS"), $r->getConstant("FONTAWESOME_CSS_INTEGRITY")]]);';
    return JSON.parse(execFileSync('php', ['-r', code, ASSET_PHP], { encoding: 'utf8' }));
}

class Browser {
    constructor(ws) {
        this.ws = ws;
        this.next = 1;
        this.waiting = new Map();
        this.session = null;
        ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            const resolver = this.waiting.get(msg.id);
            if (resolver !== undefined) {
                this.waiting.delete(msg.id);
                resolver(msg);
            }
        });
    }

    send(method, params = {}, useSession = true) {
        const id = this.next;
        this.next += 1;
        const frame = { id, method, params };
        if (useSession === true && this.session !== null) {
            frame.sessionId = this.session;
        }
        return new Promise((resolve, reject) => {
            this.waiting.set(id, (msg) => {
                if (msg.error !== undefined) {
                    reject(new Error(`${method}: ${msg.error.message}`));
                    return;
                }
                resolve(msg.result);
            });
            this.ws.send(JSON.stringify(frame));
            setTimeout(() => {
                if (this.waiting.has(id) === true) {
                    this.waiting.delete(id);
                    reject(new Error(`${method}: no answer within 30s`));
                }
            }, 30000);
        });
    }

    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', {
            expression: `(async function(){${expression}})().then(JSON.stringify)`,
            returnByValue: true,
            awaitPromise: true,
        });
        if (result.exceptionDetails !== undefined) {
            throw new Error(`page threw: ${result.exceptionDetails.text} ${
                result.exceptionDetails.exception?.description ?? ''}`);
        }
        return JSON.parse(result.result.value);
    }
}

/* Measured in the page, for every header: the furthest right any part of it
   reaches, against the right-hand edge of the column it sits in. A part
   running past that edge is what makes a phone scroll sideways. `mobile:
   false` below matters: with `mobile: true` Chromium WIDENS the viewport to
   fit wide content, and every width test passes on a broken page
   (tools/layout-audit.mjs's header records the trap). */
const MEASURE = (selector) => `
    await document.fonts.ready;
    const out = [];
    for (const probe of document.querySelectorAll('${selector}')) {
        const edge = probe.getBoundingClientRect().right;
        let worst = 0;
        for (const el of probe.querySelectorAll('*')) {
            const r = el.getBoundingClientRect();
            if (r.width === 0 && r.height === 0) { continue; }
            worst = Math.max(worst, r.right - edge);
        }
        out.push({ where: probe.dataset.where, over: Math.round(worst * 10) / 10 });
    }
    return { page: document.documentElement.scrollWidth, headers: out };
`;

/* The premise: the real stylesheets applied. Without Bootstrap every
   `d-flex` is a plain block, nothing overflows, and the test would pass on
   anything. Without Font Awesome the icons take no room and headers look
   narrower than they are. */
const PREMISE = `
    await document.fonts.ready;
    const probe = document.querySelector('.probe .d-flex');
    const icon = document.querySelector('.probe .fa-solid');
    const faLoaded = [...document.fonts].some((f) => /Font Awesome/.test(f.family) && f.status === 'loaded');
    return {
        bootstrap: probe !== null && getComputedStyle(probe).display === 'flex',
        portal: getComputedStyle(document.querySelector('.portal-main')).getPropertyValue('--portal-text') !== ''
            || getComputedStyle(document.documentElement).getPropertyValue('--portal-text') !== '',
        fontAwesome: icon !== null && faLoaded,
    };
`;

/* 🧪 FIVE MADE-UP HEADERS, ONE FOR EACH SHAPE THE AUDIT CHECK ACCEPTS.
   Why they exist: with the rule taken out, only nine real headers overflowed
   at 320px, and every one was a title DIRECTLY in the row — so narrowing the
   rule to `:has(> h1)` (dropping the title-and-subtitle shape) left every
   real header fitting and this test green (measured, 15 November 2026). So
   each shape gets a header made wide enough that ONLY wrapping can make it
   fit: one-word button labels (a `.btn` breaks at spaces, so a label with a
   space would just fold), and a long one-word title.
     S1  the <h1> is the row's child, buttons in a `d-flex` group
     S2  the <h1> is in a title block one level down (so the row is its
         grandparent), buttons directly in the row — only the row wrapping
         can save it
     S3  a `flex-column flex-md-row` header whose button group is too wide
         for a phone on its own — only the group wrapping can save it
     S4  an icon beside a title and a subtitle, written the way the audit
         check asks for it: the icon INSIDE the <h1>, so the <h1> sits in a
         title block one level down and the row is its grandparent, as in S2
         but with a button group as well
     S5  an icon in a `d-flex` block of its own, so the <h1> is a
         great-grandchild of the row and out of the rule's reach — the shape
         the check accepts only when the row AND its button group say
         `flex-wrap` themselves (the #833 fix). The rule still reaches the
         inner icon-and-title block (its <h1> is that block's grandchild),
         but the row and the buttons wrap only because they say so. If it
         fits, the check is right to accept the shape.
   Each is ALSO drawn as a CONTROL with `flex-nowrap` on every flex box, and
   the control must overflow: a fixture narrow enough to fit without
   wrapping would prove nothing, and this is how that is known not to be
   the case rather than assumed.

   ONE MORE, DRAWN WHOLE, THAT MUST *NOT* FIT: the shape the audit check
   REFUSES (round 2 of its check). It is S5 without `flex-wrap` on the row or
   the group. The rule wraps the inner icon-and-title block and nothing else;
   the spread row and its buttons stay on one line. The first version of the
   check passed it, and this test was green too, because it drew only the
   inner block (the outermost flex box within the rule's reach) — the row
   that overflows was never drawn. Drawn whole it must overflow with the
   rule in place: if it ever fits, the rule has been widened and the check's
   refusal is crying wolf, so both should be looked at together. */
const BUTTONS = ['Download', 'Statistics', 'Reconcile', 'Duplicate', 'Archive']
    .map((w) => `<a class="btn btn-outline-primary"><i class="fa-solid fa-gear me-1"></i>${w}</a>`).join('');
const SYNTHETIC = [
    ['synthetic S1 (title in the row)',
     `<div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0"><i class="fa-solid fa-users me-2"></i>Reconciliation</h1>
        <div class="d-flex gap-2">${BUTTONS}</div></div>`],
    ['synthetic S2 (title in a block one level down)',
     `<div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="mb-1">Reconciliation</h1><p class="text-muted mb-0">Every gift.</p></div>
        <a class="btn btn-primary">Download</a><a class="btn btn-primary">Statistics</a></div>`],
    ['synthetic S3 (a column header)',
     `<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div><h1 class="mb-1">Members</h1></div>
        <div class="d-flex gap-2">${BUTTONS}</div></div>`],
    ['synthetic S4 (icon beside a title block, icon inside the <h1>)',
     `<div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="mb-1"><i class="fa-solid fa-users me-2"></i>Reconciliation</h1><p class="text-muted mb-0">Every gift.</p></div>
        <div class="d-flex gap-2">${BUTTONS}</div></div>`],
    ['synthetic S5 (icon in its own d-flex block, the row and its group say flex-wrap)',
     `<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center"><i class="fa-solid fa-users fa-2x me-3"></i>
          <div><h1 class="mb-1">Reconciliation</h1><p class="text-muted mb-0">Every gift.</p></div></div>
        <div class="d-flex flex-wrap gap-2">${BUTTONS}</div></div>`],
];
const REFUSED = [
    ['refused shape (icon-and-title d-flex block, <h1> three levels inside the spread row)',
     `<div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center"><i class="fa-solid fa-users fa-2x me-3"></i>
          <div><h1 class="mb-1">Reconciliation</h1><p class="text-muted mb-0">Every gift.</p></div></div>
        <div class="d-flex gap-2">${BUTTONS}</div></div>`],
];
const controlOf = (markup) => markup.replace(/class="d-flex/g, 'class="flex-nowrap d-flex');

function escapeAttr(s) {
    return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

async function main() {
    if (typeof WebSocket === 'undefined') {
        console.error(`REFUSED: this node (${process.version}) has no global WebSocket.`);
        process.exit(2);
    }
    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        console.error('REFUSED: no Chromium-family browser found. Set CHROME_PATH.');
        process.exit(2);
    }

    let headers;
    let sheets;
    try {
        headers = JSON.parse(execFileSync('python3', ['-I', CHECK, '--headers-json'], { encoding: 'utf8' }));
        sheets = cdnSheets();
    } catch (error) {
        console.error(`REFUSED: could not read the headers or the stylesheet addresses: ${error.message}`);
        process.exit(2);
    }
    if (headers.length === 0) {
        // A finder that finds nothing would make every check below pass.
        console.error('REFUSED: the audit check found no page headers at all, so there is nothing to measure.');
        process.exit(2);
    }
    console.log(`Browser: ${browserPath}`);
    console.log(`Serving assets from: ${ASSETS}`);
    console.log(`Headers to draw: ${headers.length}`);

    const cdnLinks = sheets.map(([href, integrity]) =>
        `<link rel="stylesheet" href="${escapeAttr(href)}" integrity="${escapeAttr(integrity)}" crossorigin="anonymous">`);
    const page = `<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/brand-fonts.css">
${cdnLinks[0]}
${cdnLinks[1]}
<link rel="stylesheet" href="/assets/css/portal.css">
</head><body>
<main class="portal-main" id="main-content"><div class="container">
${headers.map((h) => `<div class="probe" data-where="${escapeAttr(h.where)}">\n${h.markup}\n</div>`).join('\n')}
${SYNTHETIC.map(([where, markup]) => `<div class="probe" data-where="${escapeAttr(where)}">\n${markup}\n</div>`).join('\n')}
<div style="overflow: hidden">
${SYNTHETIC.map(([where, markup]) => `<div class="control" data-where="${escapeAttr(where)}">\n${controlOf(markup)}\n</div>`).join('\n')}
${REFUSED.map(([where, markup]) => `<div class="refused" data-where="${escapeAttr(where)}">\n${markup}\n</div>`).join('\n')}
</div>
</div></main></body></html>`;

    const work = await mkdtemp(path.join(tmpdir(), 'page-headers-'));
    const profile = path.join(work, 'profile');

    /* Node serves the page and the real asset files; nothing else is needed.
       Only paths under /assets/ are served, resolved inside ASSETS and refused
       if they climb out of it. Port 0 lets the system pick a free port. */
    const types = { '.css': 'text/css', '.woff2': 'font/woff2', '.html': 'text/html; charset=utf-8' };
    const server = createServer(async (request, answer) => {
        const url = new URL(request.url, 'http://x').pathname;
        if (url === '/' || url === '/index.html') {
            answer.writeHead(200, { 'Content-Type': types['.html'] }).end(page);
            return;
        }
        const rel = url.startsWith('/assets/') ? decodeURIComponent(url.slice('/assets/'.length)) : null;
        const file = rel === null ? null : path.resolve(ASSETS, rel);
        if (file === null || file.startsWith(path.resolve(ASSETS) + path.sep) === false) {
            answer.writeHead(404).end('not found');
            return;
        }
        try {
            const body = await readFile(file);
            answer.writeHead(200, { 'Content-Type': types[path.extname(file)] ?? 'application/octet-stream' });
            answer.end(body);
        } catch {
            answer.writeHead(404).end('not found');
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;

    const edge = spawn(browserPath, [
        '--headless=new',
        '--remote-debugging-port=0',
        `--user-data-dir=${profile}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-gpu',
        '--hide-scrollbars',
    ], { stdio: 'ignore' });

    /* Wait for the browser to EXIT before removing its profile: deleting at
       once raced its cache writer (csrf-carriers-selftest.mjs learned that). */
    const stop = async () => {
        const ended = new Promise((resolve) => { edge.once('exit', resolve); setTimeout(resolve, 5000); });
        try { edge.kill('SIGTERM'); } catch { /* already gone */ }
        await ended;
        try { server.close(); } catch { /* already closed */ }
        for (let i = 0; i < 20; i += 1) {
            try { await rm(work, { recursive: true, force: true }); return; } catch {
                await new Promise((r) => setTimeout(r, 250));
            }
        }
        console.log(`NOTE: could not remove ${work} — please delete it by hand.`);
    };

    let failed = 0;
    try {
        /* Read the port the browser CHOSE, from its own profile folder. */
        const portFile = path.join(profile, 'DevToolsActivePort');
        let wsUrl = null;
        for (let i = 0; i < 200; i += 1) {
            try {
                const chosen = (await readFile(portFile, 'utf8')).split('\n')[0].trim();
                const info = await fetch(`http://127.0.0.1:${chosen}/json/version`);
                wsUrl = (await info.json()).webSocketDebuggerUrl;
                break;
            } catch { await new Promise((r) => setTimeout(r, 100)); }
        }
        if (wsUrl === null) { throw new Error('the browser never opened its debugging port'); }
        const socket = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve);
            socket.addEventListener('error', () => reject(new Error('could not attach to the browser')));
        });
        const browser = new Browser(socket);
        const target = await browser.send('Target.createTarget', { url: 'about:blank' }, false);
        const attached = await browser.send('Target.attachToTarget', { targetId: target.targetId, flatten: true }, false);
        browser.session = attached.sessionId;
        await browser.send('Page.enable');
        await browser.send('Runtime.enable');

        for (const width of WIDTHS) {
            await browser.send('Emulation.setDeviceMetricsOverride',
                { width, height: 800, deviceScaleFactor: 1, mobile: false });
            await browser.send('Page.navigate', { url: `http://127.0.0.1:${port}/` });
            let ready = false;
            for (let i = 0; i < 300 && ready === false; i += 1) {
                ready = await browser.evaluate('return document.readyState === "complete";').catch(() => false);
                if (ready === false) { await new Promise((r) => setTimeout(r, 100)); }
            }
            if (ready === false) { throw new Error('the page never finished loading'); }

            const premise = await browser.evaluate(PREMISE);
            if (premise.bootstrap !== true || premise.portal !== true || premise.fontAwesome !== true) {
                throw new Error(`a stylesheet did not apply at ${width}px (${JSON.stringify(premise)}); `
                    + 'is the internet reachable? Measuring without them would measure nothing.');
            }
            const viewport = await browser.evaluate('return window.innerWidth;');
            if (viewport !== width) {
                throw new Error(`asked for ${width}px and the page reports ${viewport}px, so nothing measured would mean anything`);
            }

            /* The premise for the made-up headers: each control (flex-nowrap
               everywhere) really is too wide, so a pass below is earned by
               wrapping and not by a fixture that would have fitted anyway. The
               controls sit inside an `overflow: hidden` box so they cannot
               widen the page; a box's clipping does not change where its
               contents are measured to reach. */
            const controls = await browser.evaluate(MEASURE('.control'));
            for (const c of controls.headers) {
                if (c.over > 0.5) {
                    console.log(`  ✓ ${width}px: ${c.where} is ${c.over}px too wide when nothing may wrap (the premise)`);
                } else {
                    failed += 1;
                    console.log(`  ✗ ${width}px: ${c.where} FITS even when nothing may wrap, so it proves nothing — make it wider`);
                }
            }

            /* The shape the audit check refuses, drawn whole WITH the rule:
               it must still overflow, which is what makes the refusal earned. */
            const refused = await browser.evaluate(MEASURE('.refused'));
            for (const c of refused.headers) {
                if (c.over > 0.5) {
                    console.log(`  ✓ ${width}px: ${c.where} is ${c.over}px too wide even with the rule, so the check is right to refuse it`);
                } else {
                    failed += 1;
                    console.log(`  ✗ ${width}px: ${c.where} FITS with the rule, so the check's refusal of it cries wolf — look at both`);
                }
            }

            const result = await browser.evaluate(MEASURE('.probe'));
            const over = result.headers.filter((h) => h.over > 0.5);
            if (over.length === 0) {
                console.log(`  ✓ ${width}px: all ${result.headers.length} headers fit their column`);
            } else {
                failed += over.length;
                console.log(`  ✗ ${width}px: ${over.length} of ${result.headers.length} headers are wider than their column:`);
                for (const h of over) { console.log(`      ${h.where}  (${h.over}px too wide)`); }
            }
            if (result.page > width) {
                failed += 1;
                console.log(`  ✗ ${width}px: the page itself is ${result.page}px wide, so it scrolls sideways`);
            } else {
                console.log(`  ✓ ${width}px: the page is no wider than the screen (${result.page}px)`);
            }
        }
    } finally {
        await stop();
    }

    console.log('');
    console.log(failed === 0
        ? `OK: every page header fits at ${WIDTHS.join(' and ')}px.`
        : `FAIL: ${failed} problem(s) above.`);
    console.log('Drawn from the page source with "Sample text" for every printed value; see the header for what this cannot show.');
    process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => {
    console.error(`REFUSED: ${error.message}`);
    process.exit(2);
});
