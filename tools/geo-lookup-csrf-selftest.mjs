/**
 * The "Look up coordinates" script keeps the page's form token current (#1134).
 *
 * Path: tools/geo-lookup-csrf-selftest.mjs
 *
 * WHY THIS EXISTS
 * ---------------
 * `geo/lookup` and `geo/w3w-suggest` spend the page's one form token. The
 * server half (every answer carries a replacement `csrfToken`) is driven over
 * HTTP by `tools/geo-token-selftest.php`. This is the OTHER half, and it can
 * only be seen in a browser: does the page SCRIPT hand the answer to
 * `Portal.rememberCsrf()`, so the meta tag, every `csrf_token` field and every
 * `[data-csrf]` element hold the new token, so the member's Save is accepted?
 *
 * WHAT IT RUNS
 * ------------
 * The REAL script, taken out of the REAL partial
 * (`web/_core/partials/location-map-assets.php`, rendered by PHP with two
 * stand-ins for the portal's `t()` and `Router::urlForScript()`), the REAL
 * `portal.js`, a page with three of each token carrier, and a stub for the two
 * addresses written in node. Like the real page, the script is inline and runs
 * BEFORE portal.js (which is `defer`red), so `window.Portal` is only read at
 * click time, as it must be.
 *
 *   L1 pressing the button fills the boxes and every carrier holds the NEW token
 *   L2 pressing again posts the NEW token (read at the moment of posting)
 *   L3 a refusal that carries a token is handed over too
 *   L4 an answer with NO token leaves every carrier exactly as it was
 *   W1 a what3words suggestion answer hands its token over too
 *   Q1 a look-up pressed while a suggestion request is in flight WAITS for it
 *      and posts the token that request returned: never two in flight
 *   P1-P4 the service plan's drag-reorder script (`worship/plan.php`, its real
 *      inline script cut out of the page source; Sortable itself is stubbed,
 *      it is a CDN file): the second drag posts the new token, a refused save
 *      does NOT renumber the rows and says so, and drags never overlap
 *   E1 no uncaught exception and no console error
 *
 * PLANTS (environment variable GEO_LOOKUP_PLANT, applied to the RENDERED copy,
 * never to the repository file): `remember` removes the rememberCsrf call;
 * `serial` removes the one-at-a-time queue; `notoken` makes the stub answer
 * without a token. Each must turn the checks named in the output red.
 * GEO_PLAN_FILE=<path> reads the plan page's script from another copy of
 * plan.php (for example the one before this change) and must turn P red.
 *
 * CANNOT SEE: the eight real pages, Leaflet (not loaded: the maps are a
 * different part of the same script), or the server (geo-token-selftest.php).
 * REFUSES, never skips, when no Chromium-family browser or no global
 * WebSocket is present.
 *
 * Author: MWBM Partners Ltd (t/a MWservices)
 * Copyright: All Rights Reserved -- MWBM Partners Ltd
 */

import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile, mkdtemp, writeFile, copyFile, rm, mkdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

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
// fileURLToPath, never new URL(...).pathname: this repository's path has spaces and an `&`.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PLANT = process.env.GEO_LOOKUP_PLANT ?? '';

let passed = 0;
let failed = 0;
function check(name, got, want) {
    if (JSON.stringify(got) === JSON.stringify(want)) {
        passed += 1;
        console.log(`  ✓ ${name}`);
    } else {
        failed += 1;
        console.log(`  ✗ ${name}`);
        console.log(`      wanted: ${JSON.stringify(want)}`);
        console.log(`      got:    ${JSON.stringify(got)}`);
    }
}

/* The partial prints a function; two stand-ins are all it needs to be rendered. */
const RENDER = `<?php
declare(strict_types=1);
namespace Portal\\Core { class Router { public static function urlForScript(string $a): string { return '/' . $a; } } }
namespace {
    function t(string $key): string { return $key; }
    require $argv[1];
    portal_location_map_assets('fixture-nonce');
}
`;

function renderScript(workDir) {
    const renderer = path.join(workDir, 'render.php');
    return writeFile(renderer, RENDER, 'utf8').then(() => {
        const out = spawnSync('php', [renderer, path.join(ROOT, 'web', '_core', 'partials', 'location-map-assets.php')], { encoding: 'utf8' });
        if (out.status !== 0) {
            throw new Error(`could not render the partial with php: ${out.stderr || out.error}`);
        }
        const match = out.stdout.match(/<script nonce="[^"]*">([\s\S]*?)<\/script>\s*$/);
        if (match === null) {
            throw new Error('the rendered partial held no nonce\'d inline script');
        }
        return match[1];
    });
}

function plantScript(source) {
    const cut = (text, from, to) => {
        if (source.includes(from) === false) {
            throw new Error(`the plant '${PLANT}' found nothing to change (${from})`);
        }
        return text.replace(from, to);
    };
    if (PLANT === 'remember') {
        return cut(source, 'window.Portal.rememberCsrf(data);', '');
    }
    if (PLANT === 'serial') {
        return cut(source, 'var run = inFlight.then(function () {', 'var run = Promise.resolve().then(function () {');
    }
    return source;
}

/* The plan page's own inline drag script, cut out of the real page. Its PHP
   echoes are replaced by what PHP would print; nothing else is touched, so a
   change to the real script is what runs here. */
async function planScript() {
    const source = await readFile(process.env.GEO_PLAN_FILE ?? path.join(ROOT, 'web', '_apps', 'worship', 'plan.php'), 'utf8');
    const from = source.indexOf("(function () {\n    'use strict';\n    if (typeof Sortable");
    const to = source.indexOf('})();\n</script>', from);
    if (from < 0 || to < 0) { throw new Error('could not find the drag script in worship/plan.php'); }
    let js = source.slice(from, to + 5);
    js = js.replace(/<\?php echo json_encode\(\$csrf\); \?>/, '"ORIGINAL"');
    js = js.replace(/<\?php echo Router::urlForScript\('worship\/plan\/reorder'\); \?>/, '/worship/plan/reorder');
    js = js.replace(/<\?php echo json_encode\(('[^']*')\); \?>/g, '$1');
    if (js.includes('<?php') === true) { throw new Error('the drag script holds PHP this test does not know how to print'); }
    return js;
}

const PLAN_PAGE = (inline) => `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="csrf-token" content="ORIGINAL"><title>plan fixture</title></head>
<body>
<div><div id="planItemList" data-plan-id="7">
  <div class="portal-data-row" data-item-id="11"><span class="badge bg-secondary">1</span><span class="portal-data-row-main">a</span></div>
  <div class="portal-data-row" data-item-id="12"><span class="badge bg-secondary">2</span><span class="portal-data-row-main">b</span></div>
  <div class="portal-data-row" data-item-id="13"><span class="badge bg-secondary">3</span><span class="portal-data-row-main">c</span></div>
</div></div>
<form><input type="hidden" name="csrf_token" value="ORIGINAL"></form>
<script>window.Sortable = { create: function (el, options) { window.__onEnd = options.onEnd; } };</script>
<script src="/portal.js" defer></script>
<script defer>document.addEventListener('DOMContentLoaded', function () { ${inline} });</script>
</body></html>`;

const PAGE = (inline) => `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="csrf-token" content="ORIGINAL">
<title>geo fixture</title></head>
<body>
<form id="f1">
  <input type="hidden" name="csrf_token" value="ORIGINAL">
  <textarea name="displayAddress">1 Fixture Road, Testville</textarea>
  <input name="latitude" value=""><input name="longitude" value="">
  <input name="what3words" data-w3w-suggest list="w3wList"><datalist id="w3wList"></datalist>
  <button type="button" class="portal-geo-lookup" data-geo-lookup data-address-freetext="displayAddress"
          data-lat-target="latitude" data-lng-target="longitude" data-w3w-field="what3words">Look up</button>
  <span class="portal-geo-lookup-status"></span>
</form>
<form><input type="hidden" name="csrf_token" value="ORIGINAL"></form>
<form><input type="hidden" name="csrf_token" value="ORIGINAL"></form>
<div data-portal-translate data-csrf="ORIGINAL"></div>
<div data-portal-translate data-csrf="ORIGINAL"></div>
<div data-portal-translate data-csrf="ORIGINAL"></div>
<script>${inline}</script>
<script src="/portal.js" defer></script>
</body></html>`;

class Browser {
    constructor(ws) {
        this.ws = ws;
        this.next = 1;
        this.waiting = new Map();
        this.session = null;
        this.errors = [];
        ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            if (msg.method === 'Runtime.exceptionThrown') {
                const d = msg.params?.exceptionDetails ?? {};
                this.errors.push(`uncaught: ${d.exception?.description ?? d.text ?? 'unknown'}`);
                return;
            }
            if (msg.method === 'Runtime.consoleAPICalled' && msg.params?.type === 'error') {
                this.errors.push(`console.error: ${(msg.params.args ?? []).map((a) => a.value ?? a.description ?? '?').join(' ')}`);
                return;
            }
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
                    reject(new Error(`${method}: no answer within 20s`));
                }
            }, 20000);
        });
    }

    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', {
            expression: `JSON.stringify((function(){${expression}})())`,
            returnByValue: true,
            awaitPromise: false,
        });
        if (result.exceptionDetails !== undefined) {
            throw new Error(`page threw: ${result.exceptionDetails.text} ${result.exceptionDetails.exception?.description ?? ''}`);
        }
        return JSON.parse(result.result.value);
    }

    async goto(url) {
        await this.send('Page.navigate', { url });
        for (let i = 0; i < 100; i += 1) {
            const ready = await this.evaluate(
                'return (document.readyState === "complete" && window.Portal !== undefined) ? 1 : 0;'
            ).catch(() => 0);
            if (ready === 1) { return; }
            await new Promise((r) => setTimeout(r, 100));
        }
        throw new Error(`${url}: portal.js never finished loading`);
    }

    /** Poll a page expression until it is true: a poll is a premise, a sleep is a hope. */
    async until(expression, what, ms = 8000) {
        const end = Date.now() + ms;
        while (Date.now() < end) {
            if (await this.evaluate(expression).catch(() => false) === true) { return true; }
            await new Promise((r) => setTimeout(r, 50));
        }
        console.log(`      (timed out waiting for: ${what})`);
        return false;
    }
}

const READ = `
    var meta = document.querySelector('meta[name="csrf-token"]');
    var f = [].map.call(document.querySelectorAll('input[name="csrf_token"]'), function (e) { return e.value; });
    var c = [].map.call(document.querySelectorAll('[data-csrf]'), function (e) { return e.getAttribute('data-csrf'); });
    return { meta: meta.getAttribute('content'), fields: f, carriers: c };
`;
const ALL = (t) => ({ meta: t, fields: [t, t, t], carriers: [t, t, t] });

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
    console.log(`Browser: ${browserPath}${PLANT === '' ? '' : `   PLANT: ${PLANT}`}`);

    const work = await mkdtemp(path.join(tmpdir(), 'geo-lookup-csrf-'));
    const docroot = path.join(work, 'docroot');
    const profile = path.join(work, 'profile');
    await mkdir(docroot, { recursive: true });
    await copyFile(path.join(ROOT, 'web', 'public_html', 'assets', 'js', 'portal.js'), path.join(docroot, 'portal.js'));
    const script = plantScript(await renderScript(work));
    await writeFile(path.join(docroot, 'index.html'), PAGE(script), 'utf8');
    await writeFile(path.join(docroot, 'plan.html'), PLAN_PAGE(await planScript()), 'utf8');

    /* The stub for the two addresses, and a record of what reached it. */
    const stub = { reorder: { ok: true, csrfToken: 'R-1' }, reorderDelay: 0, lookup: { csrfToken: 'NEW-1', ok: true }, w3wDelay: 0, seen: [], inFlight: 0, maxInFlight: 0, n: 0 };
    const server = createServer((request, answer) => {
        const pathName = new URL(request.url, 'http://x').pathname;
        if (request.method === 'POST' && pathName === '/worship/plan/reorder') {
            let raw = '';
            request.on('data', (chunk) => { raw += chunk; });
            request.on('end', () => {
                stub.inFlight += 1;
                stub.maxInFlight = Math.max(stub.maxInFlight, stub.inFlight);
                const form = new URLSearchParams(raw);
                stub.seen.push({ path: pathName, token: form.get('csrf_token'), items: form.getAll('items[]') });
                setTimeout(() => {
                    stub.inFlight -= 1;
                    answer.writeHead(200, { 'Content-Type': 'application/json' });
                    answer.end(JSON.stringify(stub.reorder));
                }, stub.reorderDelay);
            });
            return;
        }
        if (request.method === 'POST' && (pathName === '/geo/lookup' || pathName === '/geo/w3w-suggest')) {
            let raw = '';
            request.on('data', (chunk) => { raw += chunk; });
            request.on('end', () => {
                stub.inFlight += 1;
                stub.maxInFlight = Math.max(stub.maxInFlight, stub.inFlight);
                const sent = new URLSearchParams(raw).get('csrf_token');
                stub.seen.push({ path: pathName, token: sent });
                const reply = pathName === '/geo/lookup'
                    ? (PLANT === 'notoken' || stub.lookup.csrfToken === null
                        ? { ok: stub.lookup.ok, lat: 1, lng: 2, source: 'stub' }
                        : { ok: stub.lookup.ok, lat: 1, lng: 2, source: 'stub', csrfToken: stub.lookup.csrfToken })
                    : { ok: true, suggestions: [{ words: 'a.b.c', nearestPlace: 'Somewhere', country: 'GB' }],
                        ...(PLANT === 'notoken' ? {} : { csrfToken: 'NEW-W' }) };
                const finish = () => {
                    stub.inFlight -= 1;
                    answer.writeHead(200, { 'Content-Type': 'application/json' });
                    answer.end(JSON.stringify(reply));
                };
                setTimeout(finish, pathName === '/geo/w3w-suggest' ? stub.w3wDelay : 0);
            });
            return;
        }
        const name = path.basename(pathName === '/' ? 'index.html' : pathName);
        readFile(path.join(docroot, name)).then((body) => {
            answer.writeHead(200, { 'Content-Type': name.endsWith('.js') ? 'text/javascript' : 'text/html; charset=utf-8' });
            answer.end(body);
        }).catch(() => answer.writeHead(404).end('not found'));
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    const edge = spawn(browserPath, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu'], { stdio: 'ignore' });

    // Wait for the browser to exit before removing its profile (a race otherwise).
    const stop = async () => {
        const ended = new Promise((resolve) => { edge.once('exit', resolve); setTimeout(resolve, 5000); });
        try { edge.kill('SIGTERM'); } catch { /* already gone */ }
        await ended;
        try { server.close(); } catch { /* already closed */ }
        for (let i = 0; i < 20; i += 1) {
            try { await rm(work, { recursive: true, force: true }); return; } catch { await new Promise((r) => setTimeout(r, 250)); }
        }
        console.log(`NOTE: could not remove ${work} — please delete it by hand.`);
    };

    try {
        let wsUrl = null;
        for (let i = 0; i < 200; i += 1) {
            try {
                const chosen = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0].trim();
                wsUrl = (await (await fetch(`http://127.0.0.1:${chosen}/json/version`)).json()).webSocketDebuggerUrl;
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
        browser.session = (await browser.send('Target.attachToTarget', { targetId: target.targetId, flatten: true }, false)).sessionId;
        await browser.send('Page.enable');
        await browser.send('Runtime.enable');
        await browser.goto(`http://127.0.0.1:${port}/index.html`);

        const press = `document.querySelector('.portal-geo-lookup').click(); return 1;`;
        const boxes = `return [document.querySelector('[name=latitude]').value, document.querySelector('[name=longitude]').value];`;
        const status = `return document.querySelector('.portal-geo-lookup-status').textContent;`;
        const idle = `return document.querySelector('.portal-geo-lookup').disabled === false;`;

        console.log('L. the Look up coordinates button');
        check('L0 premise: every carrier starts at ORIGINAL', await browser.evaluate(READ), ALL('ORIGINAL'));
        await browser.evaluate(press);
        await browser.until(idle, 'the first look-up to finish');
        check('L1a the boxes were filled', await browser.evaluate(boxes), ['1', '2']);
        check('L1b the request carried the page\'s token', stub.seen.at(-1)?.token, 'ORIGINAL');
        check('L1c EVERY carrier now holds the answer\'s new token', await browser.evaluate(READ), ALL('NEW-1'));

        stub.lookup = { csrfToken: 'NEW-2', ok: true };
        await browser.evaluate(press);
        await browser.until(idle, 'the second look-up to finish');
        check('L2a the second press posted the NEW token (read when posting, not when the page loaded)', stub.seen.at(-1)?.token, 'NEW-1');
        check('L2b and every carrier moved on again', await browser.evaluate(READ), ALL('NEW-2'));

        stub.lookup = { csrfToken: 'NEW-3', ok: false };
        await browser.evaluate(press);
        await browser.until(idle, 'the refused look-up to finish');
        check('L3a a refusal is shown to the member (not left as the dots)', (await browser.evaluate(status)) !== '…', true);
        check('L3b a refusal that carries a token is handed over too', await browser.evaluate(READ), ALL('NEW-3'));

        stub.lookup = { csrfToken: null, ok: true };
        await browser.evaluate(press);
        await browser.until(idle, 'the token-less look-up to finish');
        check('L4 an answer with NO token leaves every carrier exactly as it was', await browser.evaluate(READ), ALL('NEW-3'));

        console.log('W. what3words suggestions');
        stub.lookup = { csrfToken: 'NEW-4', ok: true };
        await browser.evaluate(`var i = document.querySelector('[name=what3words]'); i.value = 'abc.def.g'; i.dispatchEvent(new Event('input')); return 1;`);
        const arrived = await browser.until(`return document.querySelectorAll('#w3wList option').length === 1;`, 'the suggestion to arrive');
        check('W1a the suggestion reached the list', arrived, true);
        check('W1b its token reached every carrier', await browser.evaluate(READ), ALL('NEW-W'));

        console.log('Q. one request at a time');
        stub.w3wDelay = 700;
        stub.maxInFlight = 0;
        const before = stub.seen.length;
        await browser.evaluate(`var i = document.querySelector('[name=what3words]'); i.value = 'xyz.uvw.t'; i.dispatchEvent(new Event('input')); return 1;`);
        await new Promise((r) => setTimeout(r, 550)); // past the 400 ms debounce: the suggestion request is now in flight
        await browser.evaluate(press);
        await browser.until(`return document.querySelectorAll('#w3wList option').length >= 1 && document.querySelector('.portal-geo-lookup').disabled === false;`, 'both to finish');
        await new Promise((r) => setTimeout(r, 900));
        const made = stub.seen.slice(before);
        check('Q1a both requests were made, suggestion first', made.map((s) => s.path), ['/geo/w3w-suggest', '/geo/lookup']);
        check('Q1b never two at once', stub.maxInFlight, 1);
        check('Q1c the look-up posted the token the suggestion answer returned', made[1]?.token, 'NEW-W');

        console.log('P. the service plan drag-reorder script');
        await browser.goto(`http://127.0.0.1:${port}/plan.html`);
        const drag = `window.__onEnd(); return 1;`;
        const badges = `return [].map.call(document.querySelectorAll('.badge.bg-secondary'), function (b) { return b.textContent; });`;
        const moveFirstToEnd = `var l = document.getElementById('planItemList'); l.appendChild(l.firstElementChild); return 1;`;
        const noteShown = `var n = document.querySelector('.alert-warning'); return n !== null && n.classList.contains('d-none') === false;`;
        const settle = () => new Promise((r) => setTimeout(r, 400));
        stub.seen = [];
        await browser.evaluate(moveFirstToEnd);
        await browser.evaluate(drag);
        await settle();
        check('P1a the first drag posted the page\'s token and the new order', [stub.seen[0]?.token, stub.seen[0]?.items], ['ORIGINAL', ['12', '13', '11']]);
        check('P1b every carrier holds the answer\'s token', await browser.evaluate(READ), { meta: 'R-1', fields: ['R-1'], carriers: [] });
        check('P1c the rows were renumbered after a kept order', await browser.evaluate(badges), ['1', '2', '3']);
        stub.reorder = { ok: true, csrfToken: 'R-2' };
        await browser.evaluate(moveFirstToEnd);
        await browser.evaluate(drag);
        await settle();
        check('P2 the SECOND drag posted the new token', stub.seen[1]?.token, 'R-1');
        stub.reorder = { ok: false, error: 'mismatch', csrfToken: 'R-3' };
        await browser.evaluate(`document.querySelectorAll('.badge.bg-secondary').forEach(function (b) { b.textContent = '-'; }); return 1;`);
        await browser.evaluate(moveFirstToEnd);
        await browser.evaluate(drag);
        await settle();
        check('P3a a refused save does NOT renumber the rows', await browser.evaluate(badges), ['-', '-', '-']);
        check('P3b and says so on the page', await browser.evaluate(noteShown), true);
        check('P3c the refusal\'s token was handed over', (await browser.evaluate(READ)).meta, 'R-3');
        stub.reorder = { ok: true, csrfToken: 'R-4' };
        stub.reorderDelay = 500;
        stub.maxInFlight = 0;
        const sentBefore = stub.seen.length;
        await browser.evaluate(drag);
        await browser.evaluate(drag);
        await new Promise((r) => setTimeout(r, 1600));
        check('P4a drags during a save wait: never two at once', stub.maxInFlight, 1);
        check('P4b the waiting drag was saved afterwards, with the newest token', [stub.seen.length - sentBefore, stub.seen.at(-1)?.token], [2, 'R-4']);
        check('P4c a kept order takes the warning away', await browser.evaluate(noteShown), false);

        console.log('E. the page');
        check('E1 no uncaught exception and no console error', browser.errors, []);
    } finally {
        await stop();
    }
    console.log('');
    console.log(`${passed + failed} checks: ${passed} passed, ${failed} failed.`);
    process.exit(failed === 0 ? 0 : 1);
}

main().catch(async (error) => {
    console.error(`REFUSED: ${error.message}`);
    process.exit(2);
});
