/**
 * The offline page tries again by itself, and the service worker never keeps
 * a copy of anything marked "private" — both proved in a real browser.
 *
 * Path: tools/offline-page-selftest.mjs
 *
 * WHY THIS EXISTS (#865)
 * ----------------------
 * Two things about the portal's offline behaviour were guarded by nothing:
 *
 *   1. The offline page (web/public_html/offline/index.php) had only a
 *      "Try Again" button. A screen nobody touches — the noticeboard's foyer
 *      screen — that landed on it during an outage stayed there until
 *      somebody pressed the button. It now tries again once a minute.
 *   2. sw.js refuses to keep any response whose Cache-Control says
 *      "private" (`mayKeepCopy()`). Every signed-in page and the foyer screen
 *      rely on that one line, and `grep -rln "mayKeepCopy\|indexOf('private')"
 *      tools/` returned nothing before this file: loosening it left every
 *      check green.
 *
 * Both are browser behaviour. Nothing that reads a page's SOURCE can see
 * either, so this drives the REAL sw.js and the REAL offline page in a real
 * Chromium-family browser over the DevTools protocol, with node's built-in
 * WebSocket — the technique of tools/csrf-carriers-selftest.mjs, whose notes
 * on finding the browser, reading the port it chose and waiting for it to
 * exit before removing its profile are followed here.
 *
 * WHAT IT PROVES
 * --------------
 *   O  The premise: the real worker installs, takes control, and stores the
 *      offline page — or nothing below means anything.
 *   P  A page marked "X-Offline-Copy: allow" is kept, and the SAME page also
 *      marked private is NOT -- "private" alone, after another directive,
 *      and on a second header line; a stylesheet is kept, and one marked
 *      private (the same three ways, one in capitals) or "no-store" is NOT. With the network gone, the private
 *      page is answered by the offline page, never by a stored copy. The two
 *      kept controls are what make the two refusals mean something: a worker
 *      that kept nothing at all would pass a "not kept" check alone.
 *   R  With the portal unreachable, a page answered by the offline page keeps
 *      trying again by itself, landing back on the offline page each time,
 *      at the page's OWN address; and once the portal answers again, the real
 *      page comes back with nobody touching anything.
 *   B  The "Try Again" button and the browser's "online" event each try at
 *      once — and, in that part, nothing reloads by itself within the wait,
 *      so it is the button (or the event) that did it.
 *   T  On the real clock the retry waits about a minute: no reload before 49
 *      seconds, and the page back by itself within 80.
 *   D  The dark theme is applied without an error. It used to be applied
 *      from <head>, before <body> existed, and threw.
 *
 * ONE THING IS ARRANGED, AND SAID: the minute. Waiting sixty real seconds per
 * retry would make this test take minutes and teach nothing more, so for
 * part R a script added to every new page BEFORE the page's own scripts run
 * (`Page.addScriptToEvaluateOnNewDocument`) makes any `setTimeout` of ten
 * seconds or longer fire a hundred times sooner. The offline page's own
 * code is untouched; parts B, T and D run with the clock left alone, and
 * T is what pins the minute itself.
 *
 * WHAT IT CANNOT DO, said plainly
 * -------------------------------
 *   * It serves the offline page rendered by PHP's command line and the
 *     fixture pages from node, not the real portal. Whether a real portal
 *     page carries "private" (or "X-Offline-Copy: allow") is the server's
 *     side, which Auth.php decides and other tests read.
 *   * "Unreachable" is a closed connection, which is what the worker sees
 *     when a server is down. A captive portal, or a server answering with an
 *     error page, is a different thing: the worker shows that answer as it is.
 *   * It does not prove that a worker ALREADY INSTALLED picks up a new copy
 *     of the offline page. Per the Service Workers standard that happens only
 *     when sw.js itself changes, which is why sw.js's header carries the note
 *     it does; that was not measured here.
 *   * One browser family. Firefox and Safari run service workers too and are
 *     not driven.
 *
 * HOW TO RUN IT
 *   node tools/offline-page-selftest.mjs
 *   node tools/offline-page-selftest.mjs --web <a copy of web/>   (for planting)
 *
 * Needs a Chromium-family browser (CHROME_PATH, or the usual places), node
 * with a global WebSocket, and `php` on the PATH (to render the offline page,
 * which is a PHP file). It REFUSES, with the reason, when any is missing —
 * it never skips.
 *
 * Package: WebMS-Intra / self-tests
 * Author: MWBM Partners Ltd (t/a MWservices)
 * Copyright: All Rights Reserved -- MWBM Partners Ltd
 * Version: 1.0.0
 * Link: https://github.com/MWBMPartners/WebMS-Intra/issues/865
 */

import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

/* Any Chromium-family browser; the list is csrf-carriers-selftest.mjs's,
   CHROME_PATH first so a runner can say which. */
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

/* fileURLToPath, never new URL(...).pathname: this repository's path holds
   spaces and an '&', which a URL percent-encodes. */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const webArg = process.argv.indexOf('--web');
const WEB = webArg !== -1 && typeof process.argv[webArg + 1] === 'string'
    ? path.resolve(process.argv[webArg + 1])
    : path.join(ROOT, 'web');

/* The offline page's own <title>, which is how a check tells it apart. */
const OFFLINE_TITLE = 'Offline — Portal';

let passed = 0;
let failed = 0;
function check(name, ok, detail) {
    if (ok === true) {
        passed += 1;
        console.log(`  ✓ ${name}`);
    } else {
        failed += 1;
        console.log(`  ✗ ${name}`);
        console.log(`      ${detail}`);
    }
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/* ── The fixture pages ───────────────────────────────────────────────────── */
const html = (title, marker, extra = '') => `<!doctype html><html lang="en"><head><meta charset="utf-8">`
    + `<title>${title}</title></head><body><p id="marker">${marker}</p>${extra}</body></html>`;

const START = html('start', 'START',
    `<script>navigator.serviceWorker.register('/sw.js').then(function(){ window.__registered = true; },`
    + ` function(e){ window.__registered = 'failed: ' + e; });</script>`);

/* ── A very small DevTools client ─────────────────────────────────────────
   Its own copy, as every browser test here keeps one (there is no shared
   module under tools/ to import; that is recorded as a finding, not
   fixed here). */
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

    /** Run an expression (which may be a promise) and hand back its value. */
    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', {
            expression: `(async function(){${expression}})().then(function(v){ return JSON.stringify(v); })`,
            returnByValue: true,
            awaitPromise: true,
        });
        if (result.exceptionDetails !== undefined) {
            throw new Error(`page threw: ${result.exceptionDetails.text} ${
                result.exceptionDetails.exception?.description ?? ''}`);
        }
        return JSON.parse(result.result.value ?? 'null');
    }

    /** Ask until the answer is truthy, through reloads (a page being replaced
        throws "context destroyed", which is simply "not yet"). A poll is a
        premise; a sleep is a hope (#735). */
    async waitFor(expression, ms) {
        const until = Date.now() + ms;
        let last = null;
        while (Date.now() < until) {
            try {
                last = await this.evaluate(expression);
                if (last) { return last; }
            } catch { /* page in the middle of loading */ }
            await sleep(100);
        }
        return last;
    }
}

/** What the page in the tab is: its address path, its title and its marker. */
const WHERE = `return { path: location.pathname, title: document.title,
    marker: (document.getElementById('marker') || {}).textContent || null,
    ready: document.readyState };`;

async function main() {
    if (typeof WebSocket === 'undefined') {
        console.error(`REFUSED: this node (${process.version}) has no global WebSocket, so the browser cannot be driven.`);
        process.exit(2);
    }
    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        console.error('REFUSED: no Chromium-family browser found. Looked at:');
        for (const p of BROWSERS) { console.error(`  ${p}`); }
        console.error('Set CHROME_PATH to one. This test drives a REAL browser and refuses rather');
        console.error('than skipping: both things it guards are invisible to anything else (#865).');
        process.exit(2);
    }

    /* The two real files. */
    const swPath = path.join(WEB, 'public_html', 'sw.js');
    const offlinePath = path.join(WEB, 'public_html', 'offline', 'index.php');
    if (existsSync(swPath) === false || existsSync(offlinePath) === false) {
        console.error(`REFUSED: ${swPath} or ${offlinePath} is missing.`);
        process.exit(2);
    }
    const swSource = await readFile(swPath, 'utf8');
    /* The cache name sw.js uses, READ OUT OF sw.js rather than typed here, so
       a future version bump cannot leave this test looking in an empty store
       and reporting "nothing kept" for the wrong reason. */
    const cacheName = (swSource.match(/var CACHE_VERSION = '([^']+)'/) || [])[1];
    if (typeof cacheName !== 'string') {
        console.error(`REFUSED: could not read CACHE_VERSION out of ${swPath}.`);
        process.exit(2);
    }
    /* The offline page is a PHP file. PHP renders it — never a hand-written
       copy of what PHP would print, which would test the copy. */
    const rendered = spawnSync('php', [offlinePath], { encoding: 'utf8' });
    if (rendered.error !== undefined || rendered.status !== 0 || rendered.stdout.indexOf('<html') === -1) {
        console.error(`REFUSED: could not render ${offlinePath} with \`php\`: `
            + `${rendered.error?.message ?? rendered.stderr ?? 'no output'}`);
        process.exit(2);
    }
    const offlineHtml = rendered.stdout;
    console.log(`Browser: ${browserPath}`);
    console.log(`Web folder: ${WEB} (store "${cacheName}")`);

    /* ── The fixture server ────────────────────────────────────────────────
       `down` makes it close every connection WITHOUT an answer, which is what
       a fetch from the worker sees when the server is unreachable (fetch()
       rejects; networkFirst() then falls back). An error status would NOT do
       that: the worker shows any answer it gets. */
    let down = false;
    const asked = new Map();
    const server = createServer((request, answer) => {
        const where = new URL(request.url, 'http://x').pathname;
        asked.set(where, (asked.get(where) ?? 0) + 1);
        if (down === true) {
            request.socket.destroy();
            return;
        }
        const send = (status, type, body, headers = {}) => {
            answer.writeHead(status, { 'Content-Type': type, ...headers });
            answer.end(body);
        };
        switch (where) {
            case '/sw.js':
                return send(200, 'text/javascript', swSource, { 'Cache-Control': 'no-cache' });
            case '/offline/':
                return send(200, 'text/html; charset=utf-8', offlineHtml);
            case '/start':
                return send(200, 'text/html; charset=utf-8', START);
            case '/foyer':
                // Not marked for keeping, as the real foyer screen is not
                // (#493 P2b), so the offline page answers it when down.
                return send(200, 'text/html; charset=utf-8', html('Foyer', 'FOYER-LIVE'));
            case '/kept-allowed':
                return send(200, 'text/html; charset=utf-8', html('Allowed', 'ALLOWED-COPY'),
                    { 'X-Offline-Copy': 'allow' });
            case '/kept-private':
                // The marker that would let it be kept, AND "private". The
                // marker is there on purpose: without it the page would be
                // refused for lacking the marker, and the "private" line
                // would be tested by nothing.
                return send(200, 'text/html; charset=utf-8', html('Private', 'PRIVATE-COPY'),
                    { 'X-Offline-Copy': 'allow', 'Cache-Control': 'private' });
            /* "private" NOT FIRST, and on a SECOND header line (round 2 of
               #865's check). The first version sent "private" only at the
               start of the value, so a refusal rewritten to look only THERE
               (`indexOf('private') === 0`) left every check green -- while the
               foyer screen really sends "no-store, private" and Auth.php
               adds "Cache-Control: private" as a second line beside a page's
               own value. An array here makes node send two header lines; the
               browser joins them with ", " before the worker reads them. */
            case '/kept-private-after':
                return send(200, 'text/html; charset=utf-8', html('Private after', 'PRIVATE-AFTER-COPY'),
                    { 'X-Offline-Copy': 'allow', 'Cache-Control': 'no-store, private' });
            case '/kept-private-two-lines':
                return send(200, 'text/html; charset=utf-8', html('Private two lines', 'PRIVATE-TWO-COPY'),
                    { 'X-Offline-Copy': 'allow', 'Cache-Control': ['no-cache, must-revalidate', 'private'] });
            case '/static/ok.css':
                return send(200, 'text/css', 'body{}');
            case '/static/private.css':
                // Capitals and a second directive, as a real server may send.
                return send(200, 'text/css', 'p{}', { 'Cache-Control': 'Private, max-age=60' });
            case '/static/private-after.css':
                return send(200, 'text/css', 'a{}', { 'Cache-Control': 'max-age=60, private' });
            case '/static/private-two-lines.css':
                return send(200, 'text/css', 'b{}', { 'Cache-Control': ['max-age=60', 'private'] });
            case '/static/no-store.css':
                // The OTHER refusal for a static file. Pages are not refused
                // for "no-store" (PHP sends it on every page), files are.
                return send(200, 'text/css', 'i{}', { 'Cache-Control': 'no-store' });
            default:
                return send(404, 'text/plain', 'not found');
        }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    /* 127.0.0.1 counts as a secure context, which service workers require. */
    const base = `http://127.0.0.1:${server.address().port}`;

    const work = await mkdtemp(path.join(tmpdir(), 'offline-page-'));
    const profile = path.join(work, 'profile');
    const edge = spawn(browserPath, [
        '--headless=new',
        '--remote-debugging-port=0',
        `--user-data-dir=${profile}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-gpu',
    ], { stdio: 'ignore' });

    /* Wait for the browser to EXIT before removing its profile (removing it
       while its cache writer runs throws ENOTEMPTY after a passing run). */
    const stop = async () => {
        const ended = new Promise((resolve) => { edge.once('exit', resolve); setTimeout(resolve, 5000); });
        try { edge.kill('SIGTERM'); } catch { /* already gone */ }
        await ended;
        try { server.close(); } catch { /* already closed */ }
        for (let i = 0; i < 20; i += 1) {
            try { await rm(work, { recursive: true, force: true }); return; } catch { await sleep(250); }
        }
        console.log(`NOTE: could not remove ${work} — please delete it by hand.`);
    };

    try {
        /* Read the port the browser CHOSE; never name one. */
        const portFile = path.join(profile, 'DevToolsActivePort');
        let wsUrl = null;
        for (let i = 0; i < 200 && wsUrl === null; i += 1) {
            try {
                const chosen = (await readFile(portFile, 'utf8')).split('\n')[0].trim();
                wsUrl = (await (await fetch(`http://127.0.0.1:${chosen}/json/version`)).json()).webSocketDebuggerUrl;
            } catch { await sleep(100); }
        }
        if (wsUrl === null) {
            throw new Error(`the browser never opened its debugging port (${portFile})`);
        }
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

        const go = (where) => browser.send('Page.navigate', { url: base + where });
        const stored = (where) => browser.evaluate(
            `var c = await caches.open(${JSON.stringify(cacheName)}); var r = await c.match(${JSON.stringify(where)}); return r !== undefined;`
        );

        /* ── O. The premise ─────────────────────────────────────────────── */
        console.log('O. The real worker installs and takes control');
        await go('/start');
        const controlled = await browser.waitFor(
            'return window.__registered === true && navigator.serviceWorker.controller !== null;', 15000);
        const offlineKept = controlled === true ? await browser.waitFor(
            `var c = await caches.open(${JSON.stringify(cacheName)}); return (await c.match('/offline/')) !== undefined;`, 10000) : false;
        check('O1 premise: sw.js registered, took control of the page, and stored the offline page',
            controlled === true && offlineKept === true,
            `controlling: ${JSON.stringify(controlled)}, offline page stored: ${JSON.stringify(offlineKept)}`);
        if (controlled !== true) {
            throw new Error('the worker never took control, so nothing below would test anything');
        }

        /* ── P. Nothing "private" is kept ───────────────────────────────── */
        console.log('P. sw.js refuses to keep a response marked "private"');
        await go('/kept-allowed');
        await browser.waitFor(`var w = (function(){${WHERE}})(); return w.marker === 'ALLOWED-COPY';`, 10000);
        for (const [where, marker] of [['/kept-private', 'PRIVATE-COPY'], ['/kept-private-after', 'PRIVATE-AFTER-COPY'],
            ['/kept-private-two-lines', 'PRIVATE-TWO-COPY']]) {
            await go(where);
            await browser.waitFor(`var w = (function(){${WHERE}})(); return w.marker === ${JSON.stringify(marker)};`, 10000);
        }
        const twoLines = await browser.evaluate(`var r = await fetch('/static/ok.css'); await fetch('/static/private.css');
            await fetch('/static/private-after.css'); await fetch('/static/no-store.css');
            var t = await fetch('/static/private-two-lines.css'); return t.headers.get('Cache-Control');`);
        /* The worker stores a copy WITHOUT waiting for it (keepCopy() is fire
           and forget), so wait for the two that SHOULD be kept, and only then
           ask about the two that should not — by then a write of either would
           have landed alongside them. */
        const allowedKept = await browser.waitFor(`
            var c = await caches.open(${JSON.stringify(cacheName)});
            return (await c.match('/kept-allowed')) !== undefined && (await c.match('/static/ok.css')) !== undefined;`, 10000);
        await sleep(500);
        check('P1 premise: a page marked "X-Offline-Copy: allow" IS kept, and so is an ordinary stylesheet '
            + '(without these two, a worker that kept nothing would pass P2 and P3)',
            allowedKept === true, `both kept: ${JSON.stringify(allowedKept)}`);
        check('P1b premise: two Cache-Control lines reach the worker as one value, "private" last '
            + '(so P2c and P3c test a SECOND header line, as Auth.php sends it)',
            twoLines === 'max-age=60, private', `the page read ${JSON.stringify(twoLines)}`);
        for (const [label, where] of [
            ['P2 a page carrying the same "allow" marker AND "Cache-Control: private"', '/kept-private'],
            ['P2b ... AND "no-store, private" (private AFTER another directive, as the foyer screen sends it)', '/kept-private-after'],
            ['P2c ... AND "private" on a SECOND header line (as Auth.php adds it)', '/kept-private-two-lines'],
            ['P3 a stylesheet marked "Private, max-age=60" (capitals)', '/static/private.css'],
            ['P3b a stylesheet marked "max-age=60, private"', '/static/private-after.css'],
            ['P3c a stylesheet with "private" on a second header line', '/static/private-two-lines.css'],
            ['P3d a stylesheet marked "no-store" (the other refusal for a file)', '/static/no-store.css'],
        ]) {
            const kept = await stored(where);
            check(`${label} is NOT kept`, kept === false, `kept: ${JSON.stringify(kept)}`);
        }

        down = true;
        await go('/kept-private');
        const privateOffline = await browser.waitFor(`var w = (function(){${WHERE}})();
            return w.ready === 'complete' && (w.title === ${JSON.stringify(OFFLINE_TITLE)} || w.marker === 'PRIVATE-COPY') ? w : null;`, 10000);
        check('P4 with the server unreachable, the private page is answered by the OFFLINE page, never a stored copy',
            privateOffline !== null && privateOffline.title === OFFLINE_TITLE && privateOffline.marker !== 'PRIVATE-COPY',
            `shown: ${JSON.stringify(privateOffline)}`);
        await go('/kept-allowed');
        const allowedOffline = await browser.waitFor(`var w = (function(){${WHERE}})();
            return w.ready === 'complete' && (w.title === ${JSON.stringify(OFFLINE_TITLE)} || w.marker === 'ALLOWED-COPY') ? w : null;`, 10000);
        check('P5 ... while the page that MAY be kept is shown from its stored copy (the control for P4)',
            allowedOffline !== null && allowedOffline.marker === 'ALLOWED-COPY',
            `shown: ${JSON.stringify(allowedOffline)}`);

        /* ⚠️ COUNT PAGE LOADS IN THE TAB, NOT REQUESTS AT THE SERVER. The
              first version counted how often the server was asked for
              /foyer, and with the clock left alone ONE navigation counted
              three: the browser retries a request whose connection was reset
              before giving up, by itself. That count could not tell the
              page's own retry from the browser's, so a check built on it
              could pass on a page that never retried at all. This counter is
              bumped by a script the browser runs at the start of every new
              document at /foyer — the offline page shown there included — so
              it counts exactly the loads the TAB made. */
        await browser.send('Page.addScriptToEvaluateOnNewDocument', {
            source: `try { if (location.pathname === '/foyer') {
                sessionStorage.setItem('loads', String((Number(sessionStorage.getItem('loads')) || 0) + 1)); } } catch (e) {}`,
        });
        const loads = () => browser.waitFor(`return Number(sessionStorage.getItem('loads')) || -1;`, 5000);
        const resetLoads = () => browser.evaluate(`sessionStorage.setItem('loads', '0'); return true;`);

        /* ── R. It tries again by itself ────────────────────────────────── */
        console.log('R. The offline page tries again by itself');
        const faster = await browser.send('Page.addScriptToEvaluateOnNewDocument', {
            source: `(function(){ var real = window.setTimeout;
                window.setTimeout = function(fn, ms){ var rest = Array.prototype.slice.call(arguments, 2);
                    if (typeof ms === 'number' && ms >= 10000) { ms = ms / 100; }
                    return real.apply(window, [fn, ms].concat(rest)); }; })();`,
        });
        await resetLoads();
        asked.set('/foyer', 0);
        await go('/foyer');
        const firstLanding = await browser.waitFor(`var w = (function(){${WHERE}})();
            return w.ready === 'complete' && w.title === ${JSON.stringify(OFFLINE_TITLE)} ? w : null;`, 10000);
        check('R1 premise: with the server unreachable, the foyer address is answered by the offline page, '
            + 'and the address in the bar is still the foyer\'s (so a reload asks for the foyer again)',
            firstLanding !== null && firstLanding.path === '/foyer',
            `shown: ${JSON.stringify(firstLanding)}`);
        /* Three accelerated minutes is about 1.8 seconds. */
        await sleep(3000);
        const stillOffline = await browser.waitFor(`var w = (function(){${WHERE}})();
            return w.ready === 'complete' ? w : null;`, 5000);
        const loadsWhileDown = await loads();
        check('R2 while it stays unreachable, the tab loads the foyer address again by itself '
            + '(at least three loads counting the first) and lands back on the offline page each time',
            loadsWhileDown >= 3 && stillOffline !== null && stillOffline.title === OFFLINE_TITLE && stillOffline.path === '/foyer',
            `the tab loaded /foyer ${loadsWhileDown} time(s) (the server was asked ${asked.get('/foyer') ?? 0} time(s), `
                + `which counts the browser's own connection retries too); now showing ${JSON.stringify(stillOffline)}`);
        down = false;
        const back = await browser.waitFor(`var w = (function(){${WHERE}})(); return w.marker === 'FOYER-LIVE' ? w : null;`, 10000);
        check('R3 once the server answers again, the real foyer page comes back with nobody touching anything',
            back !== null && back.path === '/foyer', `shown: ${JSON.stringify(back)}`);
        await browser.send('Page.removeScriptToEvaluateOnNewDocument', { identifier: faster.identifier });

        /* ── B. The button and the "online" event, on the real clock ────── */
        console.log('B. "Try Again" and coming back on line each try at once');
        for (const [label, how] of [
            ['B1 pressing "Try Again"', `document.getElementById('offline-retry').click(); return true;`],
            ['B2 the browser saying it is back on line', `window.dispatchEvent(new Event('online')); return true;`],
        ]) {
            down = true;
            await resetLoads();
            await go('/foyer');
            const landed = await browser.waitFor(`var w = (function(){${WHERE}})();
                return w.ready === 'complete' && w.title === ${JSON.stringify(OFFLINE_TITLE)} ? w : null;`, 10000);
            await sleep(2000);
            const loadsBefore = await loads();
            down = false;
            await browser.evaluate(how).catch(() => true);
            const shown = await browser.waitFor(`var w = (function(){${WHERE}})(); return w.marker === 'FOYER-LIVE' ? w : null;`, 10000);
            check(`${label} brings the foyer back at once — and, with the clock left alone, the tab had not loaded `
                + 'the foyer again by itself in the two seconds before, so it was this that did it',
                landed !== null && loadsBefore === 1 && shown !== null,
                `offline page shown: ${JSON.stringify(landed)}, the tab loaded /foyer ${loadsBefore} time(s) before, then shown ${JSON.stringify(shown)}`);
        }

        /* ── T. About a minute, on the real clock ────────────────────────
           Round 2 of #865's check: the speed-up in part R makes ANY delay of
           ten seconds or more fire quickly, so `RETRY_SECONDS = 10` (a
           building of screens hammering a portal that has just come back)
           left every check green. This part leaves the clock alone and
           WAITS: nothing may reload before 49 seconds, and the page must
           come back by itself within 80. It costs about a minute of the
           run, which is the price of measuring the thing rather than the
           code that sets it. */
        console.log('T. The retry waits about a minute (real clock; this part takes about a minute)');
        down = true;
        await resetLoads();
        await go('/foyer');
        const tLanded = await browser.waitFor(`var w = (function(){${WHERE}})();
            return w.ready === 'complete' && w.title === ${JSON.stringify(OFFLINE_TITLE)} ? Date.now() : null;`, 10000);
        const t0 = Date.now();
        let earlyAt = null;
        while (Date.now() - t0 < 49000) {
            const n = await browser.evaluate(`return Number(sessionStorage.getItem('loads')) || 0;`).catch(() => 0);
            if (n > 1 && earlyAt === null) { earlyAt = Date.now() - t0; }
            await sleep(1000);
        }
        down = false;
        const tBack = await browser.waitFor(`var w = (function(){${WHERE}})(); return w.marker === 'FOYER-LIVE' ? Date.now() : null;`, 31000);
        const after = tBack === null ? null : Math.round((Date.now() - t0) / 1000);
        check('T1 on the real clock, the offline page does NOT reload within 49 seconds, and DOES bring the foyer back by itself within 80',
            tLanded !== null && earlyAt === null && tBack !== null,
            `landed: ${JSON.stringify(tLanded !== null)}, first early reload after ${JSON.stringify(earlyAt)} ms, `
                + `foyer back after about ${JSON.stringify(after)} s`);

        /* ── D. The dark theme, without an error ────────────────────────── */
        console.log('D. The dark theme is applied without an error');
        await browser.evaluate(`localStorage.setItem('portal-theme', 'dark'); return true;`);
        browser.errors.length = 0;
        down = true;
        await go('/foyer');
        const dark = await browser.waitFor(`var w = (function(){${WHERE}})();
            return w.ready === 'complete' && w.title === ${JSON.stringify(OFFLINE_TITLE)}
                ? { theme: document.documentElement.getAttribute('data-bs-theme'), bg: document.body.style.background } : null;`, 10000);
        await sleep(300);
        check('D1 with the dark theme chosen, the offline page turns dark and throws nothing '
            + '(the old <head> script reached for document.body before it existed)',
            dark !== null && dark.theme === 'dark' && dark.bg !== '' && browser.errors.length === 0,
            `page: ${JSON.stringify(dark)}, errors: ${JSON.stringify(browser.errors)}`);
        down = false;
    } finally {
        await stop();
    }

    console.log('');
    console.log(`${passed + failed} checks: ${passed} passed, ${failed} failed.`);
    console.log('Fixture pages, not the real portal: see the header for what this cannot show.');
    process.exit(failed === 0 && passed > 0 ? 0 : 1);
}

main().catch((error) => {
    console.error(`REFUSED: ${error.message}`);
    process.exit(2);
});
