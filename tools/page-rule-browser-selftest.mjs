/**
 * A real browser, asked whether the page rule lets the active robot check load (#1092).
 *
 * Path: tools/page-rule-browser-selftest.mjs
 *
 * WHY THIS EXISTS
 * ---------------
 * `tools/page-rule-selftest.php` reads the page rule off the answer and matches it
 * with a small matcher of its own. That is a reading of text. This asks the
 * browser itself: from a real page that PHP served (so the page rule is the real
 * one), it adds a script, a frame, a style sheet and a background request to the
 * addresses each provider's widget uses, and records every
 * `securitypolicyviolation` the browser raises. A page rule refuses BEFORE any
 * network fetch, so this needs no internet: an allowed address fails later with
 * a plain network error (no violation), a refused one raises a violation at once.
 *
 * HOW IT IS RUN: `page-rule-selftest.php` starts it once per provider, after
 * setting that provider's keys, with the running test server's address:
 *
 *     node tools/page-rule-browser-selftest.mjs <base-url> <provider|none>
 *
 * It exits 0 when every check holds, 1 when one fails, 2 when it cannot run
 * (no browser, no global WebSocket). It REFUSES rather than skipping, like
 * `tools/csrf-carriers-selftest.mjs`, whose launch and tidy-up shape it copies.
 *
 * WHAT IT CANNOT SHOW: that the provider's real widget then works (that needs
 * the provider's servers and real keys); only that this page's rule does not
 * stand in the way.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 */

import { spawn } from 'node:child_process';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';

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

/* What each provider's widget loads: [kind, address]. kind is script, frame, style or connect. */
const LOADS = {
    turnstile: [
        ['script', 'https://challenges.cloudflare.com/turnstile/v0/api.js'],
        ['frame', 'https://challenges.cloudflare.com/cdn-cgi/challenge-platform/x'],
    ],
    recaptcha: [
        ['script', 'https://www.google.com/recaptcha/api.js'],
        ['script', 'https://www.gstatic.com/recaptcha/releases/x/recaptcha__en.js'],
        ['frame', 'https://www.google.com/recaptcha/api2/anchor'],
        ['frame', 'https://recaptcha.google.com/recaptcha/api2/x'],
        ['connect', 'https://www.google.com/recaptcha/api2/clr'],
    ],
    hcaptcha: [
        ['script', 'https://js.hcaptcha.com/1/api.js'],
        ['frame', 'https://newassets.hcaptcha.com/captcha/v1/x/static/hcaptcha.html'],
        ['style', 'https://newassets.hcaptcha.com/x.css'],
        ['connect', 'https://hcaptcha.com/checksiteconfig'],
    ],
};

let passed = 0;
let failed = 0;
function check(name, ok, detail = '') {
    if (ok === true) { passed += 1; console.log(`PASS - ${name}`); } else { failed += 1; console.log(`FAIL - ${name} ${detail}`); }
}

class Cdp {
    constructor(socket) { this.socket = socket; this.id = 0; this.waiting = new Map(); this.session = undefined;
        socket.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id !== undefined && this.waiting.has(m.id)) { this.waiting.get(m.id)(m); this.waiting.delete(m.id); } });
    }
    send(method, params = {}, useSession = true) {
        const id = (this.id += 1);
        const body = { id, method, params };
        if (useSession === true && this.session !== undefined) { body.sessionId = this.session; }
        return new Promise((resolve, reject) => {
            this.waiting.set(id, (m) => (m.error ? reject(new Error(`${method}: ${m.error.message}`)) : resolve(m.result)));
            this.socket.send(JSON.stringify(body));
        });
    }
    async evaluate(expression) {
        const r = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (r.exceptionDetails) { throw new Error(`page threw: ${r.exceptionDetails.text}`); }
        return r.result.value;
    }
}

async function main() {
    const base = process.argv[2];
    const active = process.argv[3];
    if (typeof base !== 'string' || /^http:\/\/127\.0\.0\.1:\d+$/.test(base) === false || (active !== 'none' && (active in LOADS) === false)) {
        console.error('usage: node tools/page-rule-browser-selftest.mjs http://127.0.0.1:PORT <turnstile|recaptcha|hcaptcha|none>');
        process.exit(2);
    }
    if (typeof WebSocket === 'undefined') {
        console.error(`REFUSED: this node (${process.version}) has no global WebSocket, so the browser cannot be driven.`);
        process.exit(2);
    }
    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        console.error('REFUSED: no Chromium-family browser found (set CHROME_PATH). It refuses rather than skipping.');
        process.exit(2);
    }
    const work = await mkdtemp(path.join(tmpdir(), 'page-rule-browser-'));
    const profile = path.join(work, 'profile');
    const edge = spawn(browserPath, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu'], { stdio: 'ignore' });
    const stop = async () => {
        const ended = new Promise((resolve) => { edge.once('exit', resolve); setTimeout(resolve, 5000); });
        try { edge.kill('SIGTERM'); } catch { /* already gone */ }
        await ended;
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
        if (wsUrl === null) { throw new Error('the browser never opened its debugging port'); }
        const socket = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => { socket.addEventListener('open', resolve); socket.addEventListener('error', () => reject(new Error('could not attach'))); });
        const cdp = new Cdp(socket);
        const target = await cdp.send('Target.createTarget', { url: 'about:blank' }, false);
        cdp.session = (await cdp.send('Target.attachToTarget', { targetId: target.targetId, flatten: true }, false)).sessionId;
        await cdp.send('Page.enable');
        await cdp.send('Runtime.enable');
        /* The listener is installed before ANY page script runs, so nothing is missed. */
        await cdp.send('Page.addScriptToEvaluateOnNewDocument', { source:
            'window.__v = []; document.addEventListener("securitypolicyviolation", function (e) { window.__v.push(e.blockedURI + " | " + e.effectiveDirective); });' });

        for (const pagePath of ['/decision-card', '/calendar/submit']) {
            await cdp.send('Page.navigate', { url: `${base}${pagePath}` });
            await new Promise((r) => setTimeout(r, 1500));
            check(`${pagePath} is the real page (it has the portal's own heading)`,
                (await cdp.evaluate('document.title.length > 0 && document.querySelector("form") !== null')) === true);
            for (const [provider, loads] of Object.entries(LOADS)) {
                const mustBeAllowed = provider === active || (provider === 'turnstile');
                for (const [kind, url] of loads) {
                    await cdp.evaluate('window.__v = [];');
                    await cdp.evaluate(`(function () {
                        var u = ${JSON.stringify(url)}, k = ${JSON.stringify(kind)};
                        if (k === 'script') { var s = document.createElement('script'); s.src = u; document.head.appendChild(s); }
                        if (k === 'frame') { var f = document.createElement('iframe'); f.src = u; document.body.appendChild(f); }
                        if (k === 'style') { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = u; document.head.appendChild(l); }
                        if (k === 'connect') { fetch(u, { mode: 'no-cors' }).catch(function () {}); }
                    })();`);
                    await new Promise((r) => setTimeout(r, 700));
                    const violations = await cdp.evaluate('window.__v');
                    const refused = violations.some((v) => v.startsWith(url.split('/').slice(0, 3).join('/')) || v.startsWith(url)) === true;
                    if (mustBeAllowed === true) {
                        check(`${pagePath}, ${active} active: the browser lets ${provider}'s ${kind} ${url} through`, refused === false, JSON.stringify(violations));
                    } else if (provider !== 'turnstile') {
                        check(`${pagePath}, ${active} active: the browser REFUSES ${provider}'s ${kind} ${url}`, refused === true, JSON.stringify(violations));
                    }
                }
            }
        }
    } finally {
        await stop();
    }
    console.log(`${passed + failed} browser checks: ${passed} passed, ${failed} failed.`);
    process.exit(failed === 0 ? 0 : 1);
}

main().catch(async (error) => {
    console.error(`REFUSED: ${error.message}`);
    process.exit(2);
});
