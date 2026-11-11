/**
 * The installer's browser rule (its Content Security Policy), proved in a real browser.
 *
 * Path: tools/installer-country-script-selftest.mjs
 *
 * WHAT THE BROWSER ENFORCES
 * --------------------------
 * D-133 (#942): the installer suggests a country and a time zone from the
 * installing person's own browser time zone, and "nothing is looked up or sent
 * anywhere". Four check rounds tried to prove that by READING the page's source
 * or by WATCHING a browser for a send, and each was got past (a WebSocket, a
 * timer, an `innerHTML` picture, a `pagehide` beacon, a `<SCRIPT>` in capitals,
 * the `<!-- <script` double escape, an `onload=` attribute, an `<iframe
 * srcdoc>`, a `javascript:` address, a tag built in PHP from two halves). So
 * every installer answer now carries a strict Content Security Policy
 * (web/_install/csp.php), and this file proves it in a real browser, on the
 * REAL installer page served by PHP's own web server.
 *
 * WHAT THE POLICY BLOCKS:
 *   a script without this answer's nonce (in any case or spelling, built in
 *   PHP from pieces, or inside an `<iframe srcdoc>`), inline event handlers
 *   (`onload=` and the like), `javascript:` addresses, fetch, XMLHttpRequest,
 *   WebSockets, beacons (on `pagehide` too), prefetch, and pictures, frames,
 *   fonts and media from other hosts.
 * WHAT IT DOES NOT BLOCK:
 *   navigating the page elsewhere (`location`, a `<meta http-equiv=refresh>`
 *   a script adds); a stylesheet from `cdn.jsdelivr.net` with anything after
 *   the host name (a source in a policy matches every path under it, so
 *   `https://cdn.jsdelivr.net/x?z=Europe%2FLondon` loads); DNS-prefetch and
 *   preconnect hints; and `window.open` (not measured in a headless browser).
 * (Measured in a browser during #942's checks: the refusal of nonce-less
 * scripts, handlers, `javascript:` addresses, fetch, WebSockets, beacons and
 * prefetch, and every item in the second list but `window.open`.
 * XMLHttpRequest, and pictures, frames, fonts and media from other hosts,
 * follow from the directives in csp.php and were not tried one by one.)
 *
 * The text pin (tools/audit-checks/check_installer_country_script_pinned.py)
 * guards ONLY the suggestion script. The installer's other three scripts carry
 * the nonce too (the early theme choice, the theme and colour-blind switches,
 * the password strength meter) and are guarded by nothing but review. So
 * "sends nothing" holds for the suggestion script AS WRITTEN AND PINNED, not
 * against a deliberate change to an unpinned script. And a hint or a
 * navigation written into our own code can reveal that somebody opened the
 * installer.
 *
 * WHAT IT PROVES
 * --------------
 *   C. The real installer page (the code page, which every visit starts on),
 *      served by `php -S` from a scratch copy of web/:
 *      - carries the policy, with `default-src 'none'` and a nonce;
 *      - WORKS under it: its own scripts run (the theme script sets
 *        `data-bs-theme`), its form submits and the next page draws, and the
 *        browser reports no violation of the policy by the installer's own
 *        page;
 *      - and with each of the six sends round 4 of the check proved real
 *        PLANTED into the copy (a `<SCRIPT>` in capitals, the double escape,
 *        an `onload=` attribute, an `<iframe srcdoc>`, a `javascript:` address,
 *        a tag built in PHP), a listener on another port receives NONE of them,
 *        and the browser reports the policy refusing them.
 *      - THE PLANTS ARE PROVED LIVE: the same planted copy, served with the
 *        policy taken off by the router, sends all six to the listener.
 *   B. The real suggestion script, served under the SAME policy (built by PHP
 *      from csp.php) with the nonce, in London, New York, Chicago and UTC,
 *      makes the right suggestion; and the Network domain sees nothing leave
 *      while it loads, while the country and zone boxes change, and while the
 *      page is left.
 *   A. The recorder used in B is live: a control page with NO policy sends
 *      three things 1.2 seconds after load (after the quiet spell, inside the
 *      two-second minimum), and all three are recorded.
 *
 * WHAT IT CANNOT SEE OR SHOW, said plainly
 * ----------------------------------------
 *   * What the policy does not block (above) is not tried here; DNS and TLS
 *     server names never reach the Network domain at all, so a hint is
 *     neither stopped nor seen.
 *   * Only the code page of the real installer is driven in a browser: the
 *     later pages need a database. installer-selftest.php's N14 checks, over
 *     HTTP, that THREE more pages (the Web addresses page, the finish page and
 *     the last page) carry the policy and that every script on them carries
 *     that answer's nonce.
 *   * A `pagehide` beacon was NOT recorded by the Network domain in B (measured);
 *     under the policy it is refused anyway (`connect-src 'none'`).
 *   * Same-origin requests are allowed by B's recorder (`fetch('/?z=')` passes
 *     there); under the policy a fetch to the installer's own server is still
 *     refused, but a picture from it is not (`img-src 'self'`).
 *   * It needs PHP and a Chromium-family browser, and REFUSES (exit 2) without
 *     either, never skips.
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
import { readFile, mkdtemp, mkdir, rm, cp, rename, writeFile } from 'node:fs/promises';
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
const CSP = path.join(ROOT, 'web', '_install', 'csp.php');
const WEB = path.join(ROOT, 'web');
const FIXTURE_NONCE = 'fixturenonce0123456789abcdef0123';

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
    const found = [...source.matchAll(/<script\b(?:[^>"]|"[^"]*")*>\s*(\/\/ 🌍 Suggest a country[\s\S]*?)<\/script>/gi)];
    if (found.length !== 1) {
        refuse(`expected exactly ONE suggestion script in ${INSTALLER}, found ${found.length}`);
    }
    if (found[0][1].includes('<?') === true) {
        refuse('the suggestion script now holds PHP, so it cannot be served as it stands; read this test\'s header');
    }

    return found[0][1];
}

/* ── The two lists, built by PHP from the installer's own sources ────────── */
/* The installer's own policy, for a given nonce, from csp.php itself. */
function policyFor(nonce) {
    return execFileSync('php', ['-r', 'require $argv[1]; echo installCspPolicy($argv[2]);', CSP, nonce], { encoding: 'utf8' });
}

function lists() {
    try { execFileSync('php', ['-v'], { stdio: 'ignore' }); } catch { refuse('no php on this machine: it builds the two lists from Countries.php'); }
    const code = 'require $argv[1];'
        + '$c = []; foreach (\\Portal\\Core\\Countries::choices() as $k => $n) { $c[] = [$k, $n]; }'
        + '$z = []; foreach (timezone_identifiers_list() as $id) { $z[] = [$id, \\Portal\\Core\\Countries::ofZone($id)]; }'
        + 'echo json_encode(["countries" => $c, "zones" => $z]);';
    return JSON.parse(execFileSync('php', ['-r', code, COUNTRIES], { encoding: 'utf8' }));
}

const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
function page(script, extra, nonce = '') {
    const { countries, zones } = LISTS;
    return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>country fixture</title></head><body>
<select id="country" name="country" data-chosen="0"><option value="" selected>Not chosen yet</option>
${countries.map(([k, n]) => `<option value="${esc(k)}">${esc(n)}</option>`).join('')}</select>
<select id="timezone" name="timezone" data-chosen="0"><option value="" selected>Not chosen yet</option>
${zones.map(([id, c]) => `<option value="${esc(id)}" data-country="${esc(c)}">${esc(id)}</option>`).join('')}</select>
<script${nonce === '' ? '' : ` nonce="${nonce}"`}>
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
        this.log = [];
        this.documentHeaders = {};
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
            } else if (msg.method === 'Log.entryAdded') {
                this.log.push(String(p.entry?.text ?? ''));
            } else if (msg.method === 'Runtime.exceptionThrown') {
                this.log.push(String(p.exceptionDetails?.exception?.description ?? p.exceptionDetails?.text ?? ''));
            } else if (msg.method === 'Network.responseReceived' && p.type === 'Document') {
                this.documentHeaders = p.response?.headers ?? {};
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
    // B's page is served under the installer's REAL policy (from csp.php), with
    // the nonce on the script, so the suggestion is shown to work under it.
    const pages = { '/page.html': page(script, '', FIXTURE_NONCE), '/control.html': page(script, control), '/left.html': '<!doctype html><title>left</title>' };
    const policy = policyFor(FIXTURE_NONCE);

    const work = await mkdtemp(path.join(tmpdir(), 'installer-country-'));
    const profile = path.join(work, 'profile');
    await mkdir(profile, { recursive: true });
    const server = createServer((request, answer) => {
        const name = new URL(request.url, 'http://x').pathname;
        if (pages[name] === undefined) {
            answer.writeHead(404).end('not found');
            return;
        }
        const head = { 'Content-Type': 'text/html; charset=utf-8' };
        if (name === '/page.html') { head['Content-Security-Policy'] = policy; }
        answer.writeHead(200, head);
        answer.end(pages[name]);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const edge = spawn(browserPath, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu'], { stdio: 'ignore' });
    const extraStops = [];
    const stop = async () => {
        for (const extra of extraStops) { try { await extra(); } catch { /* already gone */ } }
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
        await browser.send('Log.enable');

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
        check('B the suggestion ran under the installer\'s real policy with no violation reported',
            browser.log.filter((t) => /Content Security Policy/i.test(t)), []);

        await realInstaller(browser, work, extraStops);
    } finally {
        await stop();
    }

    console.log('');
    console.log(`${passed + failed} checks: ${passed} passed, ${failed} failed.`);
    console.log('See the header for what the policy blocks and what it does not; the text pin guards the suggestion script only.');
    process.exit(failed === 0 ? 0 : 1);
}

/* ── C. The REAL installer, served by PHP, under its own policy ───────────── */

/** A free port, chosen by the system. */
async function freePort() {
    const probe = createServer();
    await new Promise((resolve) => probe.listen(0, '127.0.0.1', resolve));
    const port = probe.address().port;
    await new Promise((resolve) => probe.close(resolve));
    return port;
}

/** Copy web/ into `to`, leaving out every secret and upload, the web folder renamed httpdocs. */
async function copyWeb(to) {
    const skip = new Set(['_auth_keys', '_uploads', '_backups', '_libraries', '.channel']);
    await cp(WEB, to, { recursive: true, filter: (from) => {
        const rel = path.relative(WEB, from);
        return rel === '' || skip.has(rel.split(path.sep)[0]) === false;
    } });
    await rename(path.join(to, 'public_html'), path.join(to, 'httpdocs'));
}

/* The router imitates the web folder's .htaccess, LIKE installer-selftest.php's but not the same: it
   leaves out that one's 404 for any `.php` address other than the three real ones, and its special case
   for `/assets`, neither of which the code page needs. With STRIP set it takes the policy OFF, for the
   control run that proves the plants are live. */
const ROUTER = `<?php
declare(strict_types=1);
if (getenv('STRIP_CSP') === '1') { header_register_callback(static function (): void { header_remove('Content-Security-Policy'); }); }
$root = (string) realpath((string) $_SERVER['DOCUMENT_ROOT']);
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\\\\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
$target = $root . '/index.php';
$real   = $uri === '/' ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
$_SERVER['SCRIPT_NAME'] = substr($target, strlen($root));
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
`;

/** The six sends round 4 of the check proved real, each to the listener on its own path. */
function plants(listener) {
    const to = (what) => `http://127.0.0.1:${listener}/${what}`;
    const gif = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    return [
        ['upper', `<SCRIPT>fetch('${to('upper')}')</SCRIPT>`],
        ['double', `<script>\n// <!-- <script>\n// </script>\nfetch('${to('double')}');\n</script>`],
        ['onload', `<img alt="" src="${gif}" onload="fetch('${to('onload')}')">`],
        ['srcdoc', `<iframe srcdoc="&lt;script&gt;fetch('${to('srcdoc')}')&lt;/script&gt;"></iframe>`],
        ['jsurl', `<a id="planted-jsurl" href="javascript:fetch('${to('jsurl')}')">x</a>`],
        ['php', `<?php echo '<scr' . 'ipt>fetch("${to('php')}")</scr' . 'ipt>'; ?>`],
    ];
}

async function realInstaller(browser, work, extraStops) {
    const hits = [];
    const listener = createServer((request, answer) => {
        hits.push(new URL(request.url, 'http://x').pathname.slice(1));
        answer.writeHead(204, { 'Access-Control-Allow-Origin': '*' }).end();
    });
    await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
    extraStops.push(() => new Promise((resolve) => listener.close(resolve)));
    const listenPort = listener.address().port;

    const routerFile = path.join(work, 'router.php');
    await writeFile(routerFile, ROUTER, 'utf8');
    const serve = async (root, strip) => {
        const port = await freePort();
        const php = spawn('php', ['-d', 'display_errors=0', '-d', 'log_errors=0', '-d', `session.save_path=${work}`,
            '-S', `127.0.0.1:${port}`, '-t', path.join(root, 'httpdocs'), routerFile],
            { stdio: 'ignore', env: { ...process.env, STRIP_CSP: strip === true ? '1' : '0', PHP_CLI_SERVER_WORKERS: '' } });
        const halt = () => new Promise((resolve) => { php.once('exit', resolve); php.kill('SIGTERM'); setTimeout(resolve, 3000); });
        extraStops.push(halt);
        for (let i = 0; i < 100; i += 1) {
            try { await fetch(`http://127.0.0.1:${port}/assets/`); break; } catch { await new Promise((r) => setTimeout(r, 100)); }
        }
        return { origin: `http://127.0.0.1:${port}`, halt };
    };
    const open = async (url) => {
        browser.log = [];
        browser.documentHeaders = {};
        await browser.send('Page.navigate', { url });
        for (let i = 0; i < 100; i += 1) {
            const ready = await browser.evaluate('return document.readyState === "complete" ? 1 : 0;').catch(() => 0);
            if (ready === 1) { break; }
            await new Promise((r) => setTimeout(r, 100));
        }
        await browser.settle();
    };
    const violations = () => browser.log.filter((t) => /Content Security Policy/i.test(t));

    console.log('C. The REAL installer page, served by PHP, under its own policy');
    const clean = path.join(work, 'clean');
    await copyWeb(clean);
    let server = await serve(clean, false);
    await open(`${server.origin}/`);
    const csp = String(browser.documentHeaders['content-security-policy'] ?? browser.documentHeaders['Content-Security-Policy'] ?? '');
    check('C1 the installer\'s page carries its policy: nothing by default, and scripts only by this answer\'s nonce',
        [csp.includes("default-src 'none'"), /script-src 'nonce-[0-9a-f]{32}'/.test(csp), csp.includes("connect-src 'none'")], [true, true, true]);
    check('C2 and WORKS under it: the page is the installer\'s code page, its own theme script RAN, and the browser reports no violation by the page',
        [await browser.evaluate('return /Install/.test(document.title) && document.getElementById("owner_code") !== null;'),
         await browser.evaluate('return (document.documentElement.getAttribute("data-bs-theme") || "") !== "";'),
         violations()], [true, true, []]);
    await browser.evaluate('document.getElementById("owner_code").value = "AAAA-BBBB-CCCC-DDDD"; document.getElementById("owner_code").form.submit(); return 1;');
    await new Promise((r) => setTimeout(r, 500));
    for (let i = 0; i < 100; i += 1) {
        const ready = await browser.evaluate('return document.readyState === "complete" ? 1 : 0;').catch(() => 0);
        if (ready === 1) { break; }
        await new Promise((r) => setTimeout(r, 100));
    }
    check('C3 its form SUBMITS under the policy (`form-action \'self\'`) and the answer draws: a wrong code is refused in words, with no violation',
        [await browser.evaluate('return /not the code/i.test(document.body.innerText);'), violations()], [true, []]);
    await server.halt();

    // The six sends, planted into a copy of the installer, just inside its <body>.
    const planted = path.join(work, 'planted');
    await copyWeb(planted);
    const installer = path.join(planted, '_install', 'index.php');
    const source = await readFile(installer, 'utf8');
    const anchor = '<body>\n<div class="install-shell"';
    if (source.split(anchor).length !== 2) { refuse('the installer\'s <body> anchor for the plants was not found exactly once'); }
    await writeFile(installer, source.replace(anchor, `<body>\n${plants(listenPort).map(([, html]) => html).join('\n')}\n<div class="install-shell"`), 'utf8');
    const names = plants(listenPort).map(([name]) => name);
    const visitPlanted = async (strip) => {
        hits.length = 0;
        const run = await serve(planted, strip);
        await open(`${run.origin}/`);
        await browser.evaluate('var a = document.getElementById("planted-jsurl"); if (a) { a.click(); } return 1;').catch(() => 0);
        await browser.settle();
        await new Promise((r) => setTimeout(r, 1000));
        const seen = names.filter((n) => hits.includes(n));
        const log = violations();
        // SHOW_VIOLATIONS=1 prints the browser's own words for each refusal (how C6's counts were measured).
        if (process.env.SHOW_VIOLATIONS === '1') { console.log(log.join('\n---\n')); }
        await run.halt();
        return { seen, log };
    };
    const live = await visitPlanted(true);
    check('C4 CONTROL: with the policy taken OFF, all six planted sends really reach the listener (so the plants are live)', live.seen, names);
    const held = await visitPlanted(false);
    check('C5 with the policy ON, NONE of the six reaches the listener', held.seen, []);
    // Measured in Edge: four inline scripts refused (the capitals, the double
    // escape, the PHP-built tag, and the script inside the srcdoc frame, which
    // inherits the policy), one inline event handler and one javascript: address.
    check('C6 and the browser reports the policy refusing each: four inline scripts (capitals, double escape, PHP-built, inside the srcdoc frame), one event handler, one javascript: address',
        [held.log.filter((t) => /Executing inline script violates/i.test(t)).length,
         held.log.filter((t) => /Executing inline event handler violates/i.test(t)).length,
         held.log.filter((t) => /Running the JavaScript URL violates/i.test(t)).length], [4, 1, 1]);
}

main().catch((error) => refuse(error.message));
