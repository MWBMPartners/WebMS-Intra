/**
 * The noticeboard page can no longer fetch Babel from a public server (#575).
 *
 * Path: tools/noticeboard-babel-browser-selftest.mjs
 *
 * WHY THIS EXISTS
 * ---------------
 * The generated bundle `noticeboard.noeval.js` can add a script tag for
 * https://unpkg.com/@babel/standalone (a compiler) with NO fingerprint, but only
 * when `window.Babel` is missing and a template uses an `<x-import>` of a .jsx
 * or .tsx file. The bundle is generated outside this repository, so it cannot be
 * edited here. `web/_apps/noticeboard/index.php` therefore defines a stub
 * `window.Babel` in an inline script BEFORE the bundle, and the bundle then never
 * creates the outside tag.
 *
 * Reading the source cannot show that this WORKS: what matters is what the real
 * bundle does in a real browser. This test:
 *   1. takes the stub straight out of the real page (never a copy of it),
 *   2. serves it with the real bundle and the real self-hosted React,
 *   3. forces the one path that would fetch Babel (a .jsx x-import added at run
 *      time through the bundle's own `__dcUpdate`), and
 *   4. watches every request the browser tries to make.
 * Section B is the SAME run WITHOUT the stub: it must see the unpkg request. That
 * is what proves section A could have failed.
 *
 * Sections C and D (#1141) use the same real index.php for the HOST'S OWN save():
 * the host script is lifted out of the page (PHP echoes replaced by fixed values),
 * run beside the real portal.js against a stand-in save address that answers
 * like api/noticeboard/save (it refuses a token it did not hand out and rotates
 * the token on every accepted post). C does two saves in one visit and asserts
 * both are accepted, the second carrying the replacement; D makes the server
 * refuse and asserts the person is told. The stand-in is NOT the real handler
 * (tools/noticeboard-save-selftest.php drives that).
 *
 * `unpkg.com` is mapped to a dead local address with `--host-resolver-rules`, so
 * the test never touches the internet whatever happens.
 *
 * WHAT IT CANNOT SHOW: it does not run the real PHP page (no database, no sign-in),
 * so it cannot see the page's own security rule (CSP). That rule is not what this
 * relies on; the stub is. It needs only node (global WebSocket) and a
 * Chromium-family browser, and REFUSES, rather than skipping, if either is missing.
 */
import { spawn } from 'node:child_process';
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

// fileURLToPath, never new URL().pathname: this repository's path holds spaces and an '&'.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PUBLIC = path.join(ROOT, 'web', 'public_html');
const PAGE_SOURCE = path.join(ROOT, 'web', '_apps', 'noticeboard', 'index.php');

let passed = 0;
let failed = 0;
function check(name, got, want) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    if (ok === true) { passed += 1; console.log(`  ✓ ${name}`); return; }
    failed += 1;
    console.log(`  ✗ ${name}`);
    console.log(`      wanted: ${JSON.stringify(want)}`);
    console.log(`      got:    ${JSON.stringify(got)}`);
}

/** The real stub: the inline script in index.php that defines window.Babel. */
async function stubFromRealPage() {
    // PHP blocks go first: the nonce's own `?>` would otherwise end a tag early
    // and leave `">` at the start of the script's body.
    const raw = (await readFile(PAGE_SOURCE, 'utf8')).replace(/<\?(?:php|=)[\s\S]*?\?>/g, '');
    const tags = [...raw.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)];
    const hit = tags.find((m) => /window\.Babel\s*=/.test(m[1]) === true);
    if (hit === undefined) { return null; }
    return hit[1];
}

/**
 * The real host: the inline script in index.php that defines window.NoticeboardHost,
 * with each PHP echo swapped for a fixed value (the two it holds are the admin flag,
 * the first token, and the four api addresses).
 */
async function hostFromRealPage() {
    // The nonce's own `?>` would end a tag early, so that attribute goes first.
    const raw = (await readFile(PAGE_SOURCE, 'utf8')).replace(/ nonce="<\?php[\s\S]*?\?>"/g, '');
    const tags = [...raw.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)];
    const hit = tags.find((m) => /window\.NoticeboardHost\s*=/.test(m[1]) === true);
    if (hit === undefined) { return null; }
    return hit[1]
        .replace(/<\?php echo json_encode\(\$csrf\); \?>/g, '"tok0"')
        .replace(/<\?php echo \$isAdmin === true \? 'true' : 'false'; \?>/g, 'true')
        .replace(/<\?php echo Router::urlForScript\('([^']+)'\); \?>/g, '/$1')
        .replace(/<\?(?:php|=)[\s\S]*?\?>/g, '');
}

function hostPageHtml(host) {
    return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="csrf-token" content="tok0"><title>host fixture</title></head><body>
<div id="noticeboard-root"></div>
<script>${host}</script>
<script src="/assets/js/portal.js"></script>
</body></html>`;
}

function pageHtml(stub) {
    return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>board fixture</title></head><body>
<div id="noticeboard-root" style="min-height:300px"></div>
<script>
  window.NoticeboardHost = { mode: 'host', isAdmin: false, csrf: 'x',
    load: async function () { return []; }, save: async function () {},
    qrUrl: function (t) { return '/qr?data=' + encodeURIComponent(t); } };
</script>
${stub === null ? '' : `<script>${stub}</script>`}
<script src="/assets/vendor/react/react-18.3.1.production.min.js" defer></script>
<script src="/assets/vendor/react/react-dom-18.3.1.production.min.js" defer></script>
<script src="/assets/noticeboard/noticeboard.noeval.js" defer></script>
</body></html>`;
}

class Browser {
    constructor(ws) {
        this.ws = ws; this.next = 1; this.waiting = new Map(); this.session = null;
        this.requests = []; this.console = []; this.exceptions = [];
        ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            if (msg.method === 'Network.requestWillBeSent') { this.requests.push(msg.params.request.url); return; }
            if (msg.method === 'Runtime.exceptionThrown') {
                const d = msg.params?.exceptionDetails ?? {};
                this.exceptions.push(d.exception?.description ?? d.text ?? 'unknown'); return;
            }
            if (msg.method === 'Runtime.consoleAPICalled') {
                this.console.push((msg.params.args ?? []).map((a) => a.value ?? a.description ?? '?').join(' ')); return;
            }
            const resolver = this.waiting.get(msg.id);
            if (resolver !== undefined) { this.waiting.delete(msg.id); resolver(msg); }
        });
    }
    send(method, params = {}, useSession = true) {
        const id = this.next; this.next += 1;
        const frame = { id, method, params };
        if (useSession === true && this.session !== null) { frame.sessionId = this.session; }
        return new Promise((resolve, reject) => {
            this.waiting.set(id, (msg) => {
                if (msg.error !== undefined) { reject(new Error(`${method}: ${msg.error.message}`)); return; }
                resolve(msg.result);
            });
            this.ws.send(JSON.stringify(frame));
            setTimeout(() => {
                if (this.waiting.has(id) === true) { this.waiting.delete(id); reject(new Error(`${method}: no answer within 20s`)); }
            }, 20000);
        });
    }
    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', {
            expression: `JSON.stringify((function(){${expression}})())`, returnByValue: true,
        });
        if (result.exceptionDetails !== undefined) {
            throw new Error(`page threw: ${result.exceptionDetails.text} ${result.exceptionDetails.exception?.description ?? ''}`);
        }
        return JSON.parse(result.result.value);
    }
    async waitFor(expression, what) {
        for (let i = 0; i < 150; i += 1) {
            const ok = await this.evaluate(expression).catch(() => false);
            if (ok === true) { return; }
            await new Promise((r) => setTimeout(r, 100));
        }
        throw new Error(`timed out waiting for: ${what}`);
    }
    /** Load a page, wait until the board has mounted, then force the .jsx x-import. */
    async runScenario(url) {
        this.requests = []; this.console = []; this.exceptions = [];
        await this.send('Page.navigate', { url });
        await this.waitFor('return document.readyState === "complete" && typeof window.__dcUpdate === "function" '
            + '&& document.getElementById("noticeboard-root").children.length > 0;', 'the board to mount');
        const mounted = await this.evaluate('return document.getElementById("noticeboard-root").children.length;');
        // Force the only path that would fetch Babel: a template with a .jsx x-import,
        // added through the bundle's own public hook and rendered.
        await this.evaluate(`
            window.__dcUpdate('probe', 'html', '<x-import from="/probe.jsx" component="Probe"></x-import>', false);
            var holder = document.createElement('div'); holder.id = 'probe-root'; document.body.appendChild(holder);
            window.ReactDOM.createRoot(holder).render(window.React.createElement(window.getDC('probe')));
            return 1;`);
        await new Promise((r) => setTimeout(r, 1500));
        const loading = this.console.some((l) => l.includes('x-import: loading') && l.includes('probe.jsx'));
        const babelTags = await this.evaluate(
            'return Array.prototype.filter.call(document.scripts, function (s) { return /unpkg|babel/i.test(s.src); }).length;');
        return {
            mounted, loading, babelTags,
            unpkg: this.requests.filter((u) => /unpkg\.com/.test(u)),
            babelMessage: this.console.concat(this.exceptions).some((l) => l.includes('not supported on this portal')),
        };
    }
}

async function main() {
    if (typeof WebSocket === 'undefined') {
        console.error(`REFUSED: this node (${process.version}) has no global WebSocket.`); process.exit(2);
    }
    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        console.error('REFUSED: no Chromium-family browser found. Set CHROME_PATH to one.'); process.exit(2);
    }
    const stub = await stubFromRealPage();
    if (stub === null) {
        console.error('REFUSED: web/_apps/noticeboard/index.php has no inline script defining window.Babel.');
        console.error('That is the very fault this test guards; check_cdn_sri.py reports it too.');
        process.exit(1);
    }

    const host = await hostFromRealPage();
    if (host === null) {
        console.error('REFUSED: web/_apps/noticeboard/index.php has no inline script defining window.NoticeboardHost.');
        process.exit(1);
    }

    const work = await mkdtemp(path.join(tmpdir(), 'board-babel-'));
    const profile = path.join(work, 'profile');
    await mkdir(profile, { recursive: true });
    const types = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html; charset=utf-8' };
    // Stand-in for api/noticeboard/save: one live token; an accepted post replaces it.
    const fake = { token: 'tok0', n: 0, seen: [], inFlight: 0, maxInFlight: 0, failWith: null, delayMs: 120 };
    const server = createServer(async (request, answer) => {
        const pathname = new URL(request.url, 'http://x').pathname;
        if (pathname === '/host.html') {
            answer.writeHead(200, { 'Content-Type': types['.html'] });
            answer.end(hostPageHtml(host)); return;
        }
        if (pathname === '/api/noticeboard/save') {
            fake.inFlight += 1; fake.maxInFlight = Math.max(fake.maxInFlight, fake.inFlight);
            let body = '';
            for await (const chunk of request) { body += chunk; }
            await new Promise((r) => setTimeout(r, fake.delayMs));
            const sent = request.headers['x-csrf-token'];
            const titles = (JSON.parse(body).posters ?? []).map((p) => p.title);
            const reply = (code, payload) => { fake.inFlight -= 1; answer.writeHead(code, { 'Content-Type': 'application/json' }); answer.end(JSON.stringify(payload)); };
            if (fake.failWith !== null) {
                fake.seen.push({ sent, titles, accepted: false });
                reply(500, { status: 'error', message: fake.failWith }); return;
            }
            if (sent !== fake.token) {
                fake.seen.push({ sent, titles, accepted: false });
                reply(403, { status: 'error', message: 'CSRF check failed' }); return;
            }
            fake.n += 1; fake.token = `tok${fake.n}`;
            fake.seen.push({ sent, titles, accepted: true });
            reply(200, { status: 'ok', data: { saved: true }, meta: { csrfToken: fake.token } }); return;
        }
        if (pathname === '/with.html' || pathname === '/without.html') {
            answer.writeHead(200, { 'Content-Type': types['.html'] });
            answer.end(pageHtml(pathname === '/with.html' ? stub : null)); return;
        }
        if (pathname === '/probe.jsx') { answer.writeHead(200, { 'Content-Type': 'text/plain' }); answer.end('module.exports = function Probe() { return null; };'); return; }
        const file = path.join(PUBLIC, path.normalize(pathname).replace(/^(\.\.[/\\])+/, ''));
        if (file.startsWith(PUBLIC) === false) { answer.writeHead(403).end('no'); return; }
        try {
            const body = await readFile(file);
            answer.writeHead(200, { 'Content-Type': types[path.extname(file)] ?? 'application/octet-stream' });
            answer.end(body);
        } catch { answer.writeHead(404).end('not found'); }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    const edge = spawn(browserPath, [
        '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu',
        // Never reach the real internet: unpkg.com goes nowhere.
        '--host-resolver-rules=MAP unpkg.com 127.0.0.1',
    ], { stdio: 'ignore' });
    const stop = async () => {
        const ended = new Promise((resolve) => { edge.once('exit', resolve); setTimeout(resolve, 5000); });
        try { edge.kill('SIGTERM'); } catch { /* gone */ }
        await ended;
        try { server.close(); } catch { /* closed */ }
        for (let i = 0; i < 20; i += 1) {
            try { await rm(work, { recursive: true, force: true }); return; } catch { await new Promise((r) => setTimeout(r, 250)); }
        }
        console.log(`NOTE: could not remove ${work} - please delete it by hand.`);
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
        if (wsUrl === null) { throw new Error(`the browser never opened its debugging port. Browser: ${browserPath}`); }
        const socket = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve);
            socket.addEventListener('error', () => reject(new Error('could not attach to the browser')));
        });
        const browser = new Browser(socket);
        const target = await browser.send('Target.createTarget', { url: 'about:blank' }, false);
        browser.session = (await browser.send('Target.attachToTarget', { targetId: target.targetId, flatten: true }, false)).sessionId;
        await browser.send('Page.enable'); await browser.send('Runtime.enable'); await browser.send('Network.enable');
        const base = `http://127.0.0.1:${port}`;

        console.log('A. With the real stub from index.php');
        const a = await browser.runScenario(`${base}/with.html`);
        check('A1 the board mounted (the stub broke nothing)', a.mounted > 0, true);
        check('A2 the forced .jsx x-import really ran (premise)', a.loading, true);
        check('A3 the stub throws the plain message when Babel is used', a.babelMessage, true);
        check('A4 NO request to unpkg.com was attempted', a.unpkg, []);
        check('A5 no script element for Babel/unpkg exists', a.babelTags, 0);

        console.log('B. The same run WITHOUT the stub (proves A could fail)');
        const b = await browser.runScenario(`${base}/without.html`);
        check('B1 the board still mounted', b.mounted > 0, true);
        check('B2 the forced .jsx x-import really ran (premise)', b.loading, true);
        check('B3 without the stub the bundle DOES try unpkg.com', b.unpkg.length > 0, true);
        check('B4 without the stub a Babel script element is added', b.babelTags > 0, true);

        console.log('C. The real host: two saves in one visit (#1141)');
        await browser.send('Page.navigate', { url: `${base}/host.html` });
        await browser.waitFor('return document.readyState === "complete" && typeof window.Portal === "object" '
            + '&& typeof window.Portal.rememberCsrf === "function";', 'portal.js to load');
        // Three saves in a row without waiting, as a quick add-add-delete would: the
        // host must send them one at a time, each with the token the last answer gave.
        await browser.evaluate(`
            window.__done = 0;
            ['one', 'two', 'three'].forEach(function (t) {
                Promise.resolve(window.NoticeboardHost.save([{ id: 'p' + t, title: t }])).then(function () { window.__done += 1; });
            });
            return 1;`);
        await browser.waitFor('return window.__done === 3;', 'the three saves to finish');
        check('C1 all three saves reached the server, in order', fake.seen.map((x) => x.titles[0]), ['one', 'two', 'three']);
        check('C2 every one was ACCEPTED (none refused for a spent token)', fake.seen.map((x) => x.accepted), [true, true, true]);
        check('C3 each carried the replacement the answer before it handed back', fake.seen.map((x) => x.sent), ['tok0', 'tok1', 'tok2']);
        check('C4 only one save was ever in flight at a time', fake.maxInFlight, 1);
        check('C5 the page now holds the latest token (meta tag)', await browser.evaluate('return window.Portal.csrf();'), 'tok3');
        check('C6 no failure notice is showing', await browser.evaluate('return document.getElementById("noticeboard-save-failed") === null;'), true);

        console.log('D. A refused save is told to the person');
        fake.failWith = 'Could not save noticeboard';
        await browser.evaluate('window.__done = 0; Promise.resolve(window.NoticeboardHost.save([{ id: "px", title: "x" }])).then(function () { window.__done = 1; }); return 1;');
        await browser.waitFor('return window.__done === 1;', 'the refused save to finish');
        const shown = await browser.evaluate('var n = document.getElementById("noticeboard-save-failed"); return n === null ? null : [n.textContent, n.getAttribute("role")];');
        check('D1 a notice with role=alert appears', shown === null ? null : shown[1], 'alert');
        check('D2 it says the change could not be saved and why', shown === null ? '' : shown[0].includes('could not be saved') && shown[0].includes('Could not save noticeboard'), true);
        fake.failWith = null;
        await browser.evaluate('window.__done = 0; Promise.resolve(window.NoticeboardHost.save([{ id: "py", title: "y" }])).then(function () { window.__done = 1; }); return 1;');
        await browser.waitFor('return window.__done === 1;', 'the next save to finish');
        check('D3 a later successful save clears the notice', await browser.evaluate('return document.getElementById("noticeboard-save-failed") === null;'), true);
    } finally {
        await stop();
    }
    console.log('');
    console.log(`${passed + failed} checks: ${passed} passed, ${failed} failed.`);
    process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => { console.error(`REFUSED: ${error.message}`); process.exit(2); });
