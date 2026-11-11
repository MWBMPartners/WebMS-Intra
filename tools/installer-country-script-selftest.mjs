/**
 * What the installer's country suggestion asks for in a real browser — SUPPORTING EVIDENCE, not the guard.
 *
 * Path: tools/installer-country-script-selftest.mjs
 *
 * WHAT THE GUARD IS, AND WHY THIS IS NOT IT
 * -----------------------------------------
 * D-133 (#942): the installer suggests a country and a time zone from the
 * installing person's own browser time zone, and "nothing is looked up or sent
 * anywhere". **The guard for that promise is a pin**:
 * `tools/audit-checks/check_installer_country_script_pinned.py` requires the
 * script to equal its pinned copy, so no statement in it can change without a
 * person reading the change.
 *
 * That was settled after three rounds of trying to PROVE the absence of a send
 * by watching for one, each defeated: a text search missed a WebSocket; a list
 * of traps in node missed a timer, an `innerHTML` picture, CSS `url()` and
 * `import()`; and this file's first version — which claimed to see every
 * request — missed a `pagehide` beacon, a `<link rel=preconnect>`, a timer past
 * its window and a send on the zone box's change, with the zone reaching a real
 * listener and every check green. Proving absence by sampling routes is
 * unbounded.
 *
 * WHAT THIS DOES SHOW
 * -------------------
 *   1. The real script — read out of web/_install/index.php verbatim — run in
 *      headless Chrome, Chromium or Edge with the browser's time zone set by
 *      `Emulation.setTimezoneOverride` to London, New York, Chicago and UTC,
 *      makes the right suggestion in each (so it really RUNS there).
 *   2. While it loads, while the country box AND the zone box are changed, and
 *      while the page is left for another page, the browser's Network domain
 *      records no request to any address but the page's own:
 *      `Network.requestWillBeSent`,
 *      `Network.webSocketCreated` and new windows. Each recording lasts at
 *      least two seconds and until the network has been quiet for half a
 *      second.
 *   3. The recording is LIVE: a control page sends three things (a fetch with
 *      a built-up name, an `innerHTML` picture, a WebSocket) 1.2 seconds after
 *      loading — AFTER the half-second quiet spell, INSIDE the two-second
 *      minimum — and all three must be recorded. A recorder that stopped at
 *      the first quiet spell would miss them and go red.
 *
 * WHAT IT CANNOT SEE, said plainly
 * --------------------------------
 *   * PRE-CONNECTION AND DNS HINTS. `<link rel=preconnect>` and `dns-prefetch`
 *     send a server name over DNS and TLS, which never reach the Network
 *     domain. A zone written into such a name is invisible here.
 *   * A BEACON ON LEAVING. A planted `pagehide` `sendBeacon` was NOT recorded,
 *     whether the page was left for `about:blank` or for another page on the
 *     same origin (measured, both). So leaving the page is exercised, but a
 *     beacon sent as it goes is not seen; and a tab or browser that is CLOSED
 *     is not tried at all.
 *   * TIMERS PAST ITS WINDOW. Anything put off beyond the recording (a few
 *     seconds) is not seen.
 *   * SAME-ORIGIN SENDS. A request to the page's own address is allowed, so
 *     `fetch('/?z=' + zone)` passes. On the real installer that address is the
 *     customer's own server, which is where the person is anyway, but it is a
 *     send all the same.
 *   * The rest of the installer's page. It runs the script on a FIXTURE page
 *     (the real page needs an installation code, a database and a session),
 *     with the two lists built by PHP from the same `Portal\Core\Countries`
 *     and zone data the installer uses.
 *   * It needs PHP and a Chromium-family browser, and REFUSES (exit 2)
 *     without either, never skips.
 *
 * HOW IT IS BUILT
 * ---------------
 * The DevTools client below is a copy of the small one in
 * `tools/csrf-carriers-selftest.mjs` (there is no shared module), and it keeps
 * that file's four measured lessons: `fileURLToPath`, never
 * `new URL(...).pathname`; read the port the browser CHOSE from
 * `DevToolsActivePort`; wait for the browser to EXIT before removing its
 * profile; and node serves the fixture, so nothing but node, PHP and a browser
 * is needed.
 *
 * HOW TO RUN IT
 *   node tools/installer-country-script-selftest.mjs
 *
 * Package: WebMS-Intra / self-tests
 * Author: Claude Opus 5.5 (for MWBM Partners Ltd, t/a MWservices)
 * Copyright: All Rights Reserved -- MWBM Partners Ltd
 * Version: 1.1.0
 */

import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile, mkdtemp, mkdir, rm } from 'node:fs/promises';
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
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const INSTALLER = path.join(ROOT, 'web', '_install', 'index.php');
const COUNTRIES = path.join(ROOT, 'web', '_core', 'Countries.php');

let passed = 0;
let failed = 0;
function check(name, got, want) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    if (ok === true) {
        passed += 1;
        console.log(`  ✓ ${name}`);
    } else {
        failed += 1;
        console.log(`  ✗ ${name}`);
        console.log(`      wanted: ${JSON.stringify(want)}`);
        console.log(`      got:    ${JSON.stringify(got)}`);
    }
}
function refuse(why) {
    console.error(`REFUSED: ${why}`);
    process.exit(2);
}

/* ── The real script, out of the installer's own source ──────────────────── */
async function realScript() {
    const source = await readFile(INSTALLER, 'utf8');
    const found = [...source.matchAll(/<script>\s*(\/\/ 🌍 Suggest a country[\s\S]*?)<\/script>/g)];
    if (found.length !== 1) {
        refuse(`expected exactly ONE suggestion script in ${INSTALLER}, found ${found.length}`);
    }
    if (found[0][1].includes('<?') === true) {
        refuse('the suggestion script now holds PHP, so it cannot be served as it stands; read this test\'s header');
    }

    return found[0][1];
}

/* ── The two lists, built by PHP from the installer's own sources ────────── */
function lists() {
    try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch { refuse('no php on this machine: it builds the two lists from Countries.php'); }
    const code = 'require $argv[1];'
        + '$c = []; foreach (\\Portal\\Core\\Countries::choices() as $k => $n) { $c[] = [$k, $n]; }'
        + '$z = []; foreach (timezone_identifiers_list() as $id) { $z[] = [$id, \\Portal\\Core\\Countries::ofZone($id)]; }'
        + 'echo json_encode(["countries" => $c, "zones" => $z]);';
    return JSON.parse(execFileSync('php', ['-r', code, COUNTRIES], { encoding: 'utf8' }));
}

const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
function page(script, extra) {
    const { countries, zones } = LISTS;
    return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>country fixture</title></head><body>
<select id="country" name="country" data-chosen="0"><option value="" selected>Not chosen yet</option>
${countries.map(([k, n]) => `<option value="${esc(k)}">${esc(n)}</option>`).join('')}</select>
<select id="timezone" name="timezone" data-chosen="0"><option value="" selected>Not chosen yet</option>
${zones.map(([id, c]) => `<option value="${esc(id)}" data-country="${esc(c)}">${esc(id)}</option>`).join('')}</select>
<script>
${script}
</script>
${extra}
</body></html>`;
}
let LISTS = null;

/* ── A very small DevTools client (see the header) ───────────────────────── */
class Browser {
    constructor(ws) {
        this.ws = ws;
        this.next = 1;
        this.waiting = new Map();
        this.session = null;
        this.requests = [];
        this.inflight = new Set();
        this.lastActivity = Date.now();
        ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            const p = msg.params ?? {};
            if (msg.method === 'Network.requestWillBeSent') {
                this.requests.push(p.request?.url ?? '?');
                this.inflight.add(p.requestId);
                this.lastActivity = Date.now();
            } else if (msg.method === 'Network.loadingFinished' || msg.method === 'Network.loadingFailed') {
                this.inflight.delete(p.requestId);
                this.lastActivity = Date.now();
            } else if (msg.method === 'Network.webSocketCreated') {
                this.requests.push(`websocket ${p.url ?? '?'}`);
                this.lastActivity = Date.now();
            } else if (msg.method === 'Target.targetCreated' && p.targetInfo?.type === 'page' && p.targetInfo?.targetId !== this.ownTarget) {
                this.requests.push(`new window ${p.targetInfo?.url ?? '?'}`);
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
            expression: `JSON.stringify((function(){${expression}})())`, returnByValue: true,
        });
        if (result.exceptionDetails !== undefined) {
            throw new Error(`page threw: ${result.exceptionDetails.text}`);
        }
        return JSON.parse(result.result.value);
    }

    /** Wait until at least `min` ms have passed AND nothing has moved on the network for 500 ms. */
    async settle(min = 2000) {
        const started = Date.now();
        for (let i = 0; i < 200; i += 1) {
            const quiet = this.inflight.size === 0 && Date.now() - this.lastActivity >= 500;
            if (Date.now() - started >= min && quiet === true) { return; }
            await new Promise((r) => setTimeout(r, 100));
        }
    }

    /** Load a page in a time zone, recording from BEFORE the request; answer what it asked for. */
    async visit(url, zone) {
        await this.send('Emulation.setTimezoneOverride', { timezoneId: zone });
        this.requests = [];
        await this.send('Page.navigate', { url });
        for (let i = 0; i < 100; i += 1) {
            const ready = await this.evaluate('return document.readyState === "complete" ? 1 : 0;').catch(() => 0);
            if (ready === 1) { break; }
            await new Promise((r) => setTimeout(r, 100));
        }
        await this.settle();
    }
}

async function main() {
    if (typeof WebSocket === 'undefined') {
        refuse(`this node (${process.version}) has no global WebSocket, so the browser cannot be driven`);
    }
    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        refuse(`no Chromium-family browser found (set CHROME_PATH). Looked at: ${BROWSERS.join(', ')}`);
    }
    console.log(`Browser: ${browserPath}`);
    const script = await realScript();
    LISTS = lists();

    /* The CONTROL: the same script, then three sends 1.2 seconds after load —
       after settle()'s half-second quiet spell, inside its two-second minimum.
       A recorder that stopped at the first quiet spell misses them. */
    const control = `<script>
setTimeout(function () {
    window['fe' + 'tch']('//control-fetch.invalid/a').catch(function () {});
    var d = document.createElement('div');
    d.innerHTML = '<img src="//control-image.invalid/i">';
    document.body.appendChild(d);
    try { new WebSocket('ws://control-socket.invalid/s'); } catch (e) {}
}, 1200);
</script>`;
    // `/left.html` is somewhere to LEAVE to, on the same origin, so a send
    // made while leaving that is an ordinary request is recorded. A planted
    // `pagehide` BEACON was not recorded either way (here, or leaving for
    // about:blank) — measured — so this test does not claim beacons.
    const pages = { '/page.html': page(script, ''), '/control.html': page(script, control), '/left.html': '<!doctype html><title>left</title>' };

    const work = await mkdtemp(path.join(tmpdir(), 'installer-country-'));
    const profile = path.join(work, 'profile');
    await mkdir(profile, { recursive: true });
    const server = createServer((request, answer) => {
        const name = new URL(request.url, 'http://x').pathname;
        if (pages[name] === undefined) {
            answer.writeHead(404).end('not found');
            return;
        }
        answer.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        answer.end(pages[name]);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const edge = spawn(browserPath, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu'], { stdio: 'ignore' });
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
        const portFile = path.join(profile, 'DevToolsActivePort');
        let wsUrl = null;
        for (let i = 0; i < 200; i += 1) {
            try {
                const chosen = (await readFile(portFile, 'utf8')).split('\n')[0].trim();
                wsUrl = (await (await fetch(`http://127.0.0.1:${chosen}/json/version`)).json()).webSocketDebuggerUrl;
                break;
            } catch { await new Promise((r) => setTimeout(r, 100)); }
        }
        if (wsUrl === null) { throw new Error(`the browser never opened its debugging port (${portFile})`); }
        const socket = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve);
            socket.addEventListener('error', () => reject(new Error('could not attach to the browser')));
        });
        const browser = new Browser(socket);
        await browser.send('Target.setDiscoverTargets', { discover: true }, false);
        const target = await browser.send('Target.createTarget', { url: 'about:blank' }, false);
        browser.ownTarget = target.targetId;
        browser.session = (await browser.send('Target.attachToTarget', { targetId: target.targetId, flatten: true }, false)).sessionId;
        await browser.send('Page.enable');
        await browser.send('Runtime.enable');
        await browser.send('Network.enable');
        await browser.send('Network.setCacheDisabled', { cacheDisabled: true });

        const foreign = () => browser.requests.filter((u) => u.startsWith(origin + '/') === false);
        const chosen = () => browser.evaluate('return document.getElementById("country").value + "|" + document.getElementById("timezone").value;');

        console.log('A. The capture is live (the control page sends three things after a delay)');
        await browser.visit(`${origin}/control.html`, 'Europe/London');
        const seen = foreign();
        check('A1 the delayed fetch, the innerHTML picture and the WebSocket on the control page are ALL recorded',
            ['control-fetch.invalid', 'control-image.invalid', 'control-socket.invalid'].map((h) => seen.some((u) => u.includes(h))),
            [true, true, true]);
        check('A2 and the control page asked for itself first, so recording began before the page arrived',
            browser.requests[0], `${origin}/control.html`);

        console.log('B. The real script, in four time zones: it suggests, and the Network domain sees nothing leave');
        for (const [zone, want] of [
            ['Europe/London', 'GB|Europe/London'],
            ['America/New_York', 'US|America/New_York'],
            ['America/Chicago', 'US|America/Chicago'],
            ['UTC', '|'],
        ]) {
            await browser.visit(`${origin}/page.html`, zone);
            check(`B ${zone}: the script suggests ${want === '|' ? 'nothing' : want}`, await chosen(), want);
            check(`B ${zone}: the Network domain saw nothing asked of any address but the page's own (see the header for what it cannot see)`, foreign(), []);
            // Recorded afresh, so this check is about the two change handlers and leaving the page.
            browser.requests = [];
            await browser.evaluate('var c = document.getElementById("country"); c.value = "FR"; c.dispatchEvent(new Event("change")); '
                + 'c.value = "AU"; c.dispatchEvent(new Event("change")); '
                + 'var z = document.getElementById("timezone"); z.value = "Australia/Perth"; z.dispatchEvent(new Event("change")); return 1;');
            await browser.settle();
            await browser.send('Page.navigate', { url: `${origin}/left.html` });
            await browser.settle();
            check(`B ${zone}: and nothing while a country and a zone are chosen, or while the page is left (a beacon sent as it goes is NOT seen; see the header)`, foreign(), []);
        }
    } finally {
        await stop();
    }

    console.log('');
    console.log(`${passed + failed} checks: ${passed} passed, ${failed} failed.`);
    console.log('Supporting evidence only: the guard is check_installer_country_script_pinned.py. See the header.');
    process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => refuse(error.message));
