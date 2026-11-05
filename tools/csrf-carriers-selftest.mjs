/**
 * The replacement anti-forgery token really reaches all three carriers.
 *
 * Path: tools/csrf-carriers-selftest.mjs
 *
 * WHY THIS EXISTS
 * ---------------
 * #768's fault is entirely in the browser: `Portal.rememberCsrf()` wrote the
 * `<meta>` tag and nothing else, so after any accepted background post every
 * ordinary form on the page was holding a spent token. Nothing in this
 * repository could see that. The HTTP self-tests fetch pages and read their
 * SOURCE — and the source is correct; it is what JavaScript does to the page
 * afterwards that was wrong.
 *
 * That gap is why #485 ended up pinning a whole script as TEXT after four
 * rounds of one-edit bypasses. **A text pin cannot tell you the code works.
 * This can.**
 *
 * ⚠️ THIS IS NOT THE FIRST BROWSER TEST HERE, AND SAYING SO WAS WRONG.
 *    `tools/layout-audit.mjs` has driven Edge headless over the DevTools
 *    protocol with node's own global `WebSocket` since 30 October 2026
 *    (`0186531`, the browser half of #569) — the identical technique, in this
 *    repository, six days earlier. Establish it with
 *    `grep -rln "remote-debugging-port" tools/`, which this claim was written
 *    without running. What IS true: this is the first such test wired into a
 *    workflow, and `layout-audit.mjs` is in none.
 *
 *    It matters beyond the correction. A reader who believes there is no
 *    machinery writes a third copy of the DevTools client — which is the
 *    #768 fault itself, each new file copying the nearest thing because
 *    nobody said a shared one existed.
 *
 * It needs NOTHING installed. A Chromium-family browser is already present, and
 * node provides a global `WebSocket`, so the DevTools protocol is reachable
 * with no package, no driver and no download. `layout-audit.mjs` records that
 * node 22 is where that global arrived; this test does not re-assert it, and
 * refuses with the reason named if `WebSocket` is missing.
 *
 * WHAT IT PROVES
 * --------------
 *   1. `Portal.writeCsrf()` writes ALL THREE carriers in a real DOM — the meta
 *      tag, every `input[name="csrf_token"]`, and every `[data-csrf]` element.
 *      Several of each, not one, because a loop that stops after the first is
 *      a plausible mistake that a single-element fixture cannot catch.
 *   2. `Portal.rememberCsrf()` reads all THREE answer shapes the portal really
 *      sends (`meta.csrfToken`, top-level `csrfToken`, top-level `csrf_token`)
 *      and writes every carrier for each.
 *   3. An answer carrying NO token leaves every carrier exactly as it was.
 *      Blanking one would make the next post send nothing at all, which is a
 *      worse failure than the one being fixed.
 *   4. `rememberCsrf()` answers true when it found a token AND stored it,
 *      even on a page with no meta tag — the specific regression #768 fixed.
 *      It used to answer false there while a form on the same page sat
 *      holding a spent token.
 *   5. `Portal.csrf()` reads every carrier, not just the meta tag, and the
 *      answer to (4) is false on a page with NO carrier at all. Both halves
 *      were pinned by NOTHING until round 5: reverting either left all 18
 *      checks green, because nothing called `Portal.csrf()` and no fixture
 *      carried nothing. Section F is those two fixtures.
 *   6. The real `portal-translate.js`, `event-hub-upload.js` AND
 *      `report-builder.js` all LOAD alongside portal.js without complaint:
 *      a load-time fault in any of the four fails E2 (measured, each one
 *      planted). **That is the whole of what section E proves.** It does
 *      NOT prove any of them delegates — each is an IIFE, so `syncCsrf()`
 *      cannot be called from here, and killing the delegation three ways
 *      leaves every check green. Check F of `check_one_csrf_writer.py`
 *      guards that; #773 is the stronger answer. An earlier version of this
 *      item said "each delegates now", asserting the one thing this file
 *      cannot show — and named two of the three files.
 *
 * WHAT IT CANNOT DO, said plainly
 * -------------------------------
 *   * **It does not drive the real Host Console**, which is where the fault was
 *     measured (publish a chat prompt, then press "Send 'We're live'"). That
 *     needs a signed-in administrator, a database and a real event. What is
 *     proved here is the helper every one of those pages now calls; that the
 *     pages DO call it is check C of `check_one_csrf_writer.py`, which is a
 *     different kind of evidence and neither replaces the other.
 *   * **It cannot prove a caller hands its answer over at all.** A page that
 *     posts and ignores the reply passes everything here and still goes stale.
 *   * It serves the real asset files, but a fixture page rather than a real
 *     portal page, so it says nothing about whether a given page carries the
 *     carriers it ought to.
 *
 * HOW TO RUN IT
 *   node tools/csrf-carriers-selftest.mjs
 *
 * Package: WebMS-Intra / self-tests
 * Author: Claude Opus 5 (for MWBM Partners Ltd, t/a MWservices)
 * Copyright: All Rights Reserved -- MWBM Partners Ltd
 * Version: 1.0.0
 */

import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { mkdtemp, writeFile, copyFile, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

/* 🌐 ANY CHROMIUM-FAMILY BROWSER, NOT JUST THE ONE ON THIS MAC.
      The first version named Microsoft Edge's path and nothing else. That
      works here and could NEVER have run on GitHub, whose Linux runners have
      Chrome and Chromium but no Edge — which is #646's shape exactly: a check
      that guards something real and runs nowhere. The DevTools protocol used
      below is the same in all of them, so the only difference is the path.
      `CHROME_PATH` is honoured first so a runner can say which to use. */
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
/* ⚠️ `fileURLToPath`, NEVER `new URL(...).pathname`. A URL percent-encodes, so
      on this machine the repository's own path — which contains spaces and an
      `&` — came back as `.../Coding%20&%20Development/...` and every file
      copy failed. The test refused rather than passing, which is the right
      direction, but the message blamed a missing file. */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ASSETS = path.join(ROOT, 'web', 'public_html', 'assets', 'js');

/* The real files, copied into a scratch folder rather than read in place, so
   the browser fetches them over HTTP exactly as a visitor would — and so a
   failure cannot be blamed on a scratch edit sitting in the working tree. */
const SCRIPTS = [
    'portal.js',
    'portal-translate.js',
    'event-hub-upload.js',
    // ⚠️ ADDED AFTER A COMMENT CLAIMED IT WAS ALREADY HERE. Section E's note
    //    said this test "LOADS event-hub-upload.js and report-builder.js", and
    //    it did not — report-builder.js was neither served nor parsed. Measured
    //    consequence, not a wording slip: planting a broken
    //    `window.Portal.writeCsrf` inside event-hub-upload.js turned this test
    //    red, and the same plant inside report-builder.js left it green. So the
    //    comment asserted behavioural cover for exactly the file that had none
    //    — and report-builder.js is the file whose cost this package states
    //    twice ("Preview-then-Save is refused and the administrator loses the
    //    report definition they just built").
    'report-builder.js',
];

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

/* ── The fixture page ───────────────────────────────────────────────────────
   THREE of each carrier on purpose. One of each cannot tell a loop that writes
   every element apart from one that writes only the first — the #666 lesson
   ("a fixture that differs in several ways at once tests only the first thing
   the code looks at"), in its simplest form. */
const PAGE = `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="csrf-token" content="ORIGINAL">
<title>carrier fixture</title></head>
<body>
<form><input type="hidden" name="csrf_token" value="ORIGINAL"></form>
<form><input type="hidden" name="csrf_token" value="ORIGINAL"></form>
<form><input type="hidden" name="csrf_token" value="ORIGINAL"></form>
<div data-portal-translate data-csrf="ORIGINAL"></div>
<div data-portal-translate data-csrf="ORIGINAL"></div>
<div data-portal-translate data-csrf="ORIGINAL"></div>
${SCRIPTS.map((s) => `<script src="/${s}" defer></script>`).join('\n')}
</body></html>`;

/* ⚠️ A THIRD FIXTURE WITH NO CARRIER AT ALL, because BOTH HALVES of the
      round-3 behaviour fix were pinned by NOTHING until round 5 measured it:
      putting `getMetaCsrf()` back to meta-tag-only, and putting
      `rememberCsrf()` back to an unconditional `return true`, each left all 18
      checks passing. The reason was structural — `Portal.csrf()` was never
      called by this test, and no fixture had ZERO carriers, which is the only
      state where the honest answer and the unconditional true differ. Both
      docblocks said the fault was "measured in a real browser"; the
      measurement had been made by hand and then not kept. */
const PAGE_NO_CARRIERS = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>no carriers</title></head>
<body>
${SCRIPTS.map((s) => `<script src="/${s}" defer></script>`).join('\n')}
</body></html>`;

/* A second page with NO meta tag, for check 4. */
const PAGE_NO_META = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>no meta</title></head>
<body>
<form><input type="hidden" name="csrf_token" value="ORIGINAL"></form>
${SCRIPTS.map((s) => `<script src="/${s}" defer></script>`).join('\n')}
</body></html>`;

/* ── A very small DevTools client ───────────────────────────────────────── */
class Browser {
    constructor(ws) {
        this.ws = ws;
        this.next = 1;
        this.waiting = new Map();
        this.session = null;
        /* Everything the page complains about, collected from the moment the
           target exists. E2 requires this to be empty. */
        this.errors = [];
        ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            if (msg.method === 'Runtime.exceptionThrown') {
                const d = msg.params?.exceptionDetails ?? {};
                this.errors.push(`uncaught: ${d.exception?.description ?? d.text ?? 'unknown'}`);
                return;
            }
            if (msg.method === 'Runtime.consoleAPICalled' && msg.params?.type === 'error') {
                this.errors.push(`console.error: ${(msg.params.args ?? [])
                    .map((a) => a.value ?? a.description ?? '?').join(' ')}`);
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

    /** Run an expression in the page and hand back its value. */
    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', {
            expression: `JSON.stringify((function(){${expression}})())`,
            returnByValue: true,
            awaitPromise: false,
        });
        if (result.exceptionDetails !== undefined) {
            throw new Error(`page threw: ${result.exceptionDetails.text} ${
                result.exceptionDetails.exception?.description ?? ''}`);
        }
        return JSON.parse(result.result.value);
    }

    async goto(url) {
        await this.send('Page.navigate', { url });
        /* `Page.navigate` resolves when the navigation STARTS, so deferred
           scripts have not run yet. Poll for the helper rather than sleeping:
           a sleep is a hope, a poll is a premise (#735). */
        for (let i = 0; i < 100; i += 1) {
            const ready = await this.evaluate(
                'return (document.readyState === "complete" ' +
                '&& window.Portal !== undefined) ? 1 : 0;'
            ).catch(() => 0);
            if (ready === 1) { return; }
            await new Promise((r) => setTimeout(r, 100));
        }
        throw new Error(`${url}: portal.js never finished loading`);
    }
}

/** Read every carrier's current value. */
const READ_CARRIERS = `
    var meta = document.querySelector('meta[name="csrf-token"]');
    var fields = [], carriers = [], i;
    var f = document.querySelectorAll('input[name="csrf_token"]');
    for (i = 0; i < f.length; i += 1) { fields.push(f[i].value); }
    var c = document.querySelectorAll('[data-csrf]');
    for (i = 0; i < c.length; i += 1) { carriers.push(c[i].getAttribute('data-csrf')); }
    return { meta: meta === null ? null : meta.getAttribute('content'),
             fields: fields, carriers: carriers };
`;

async function main() {
    /* ⚠️ SAY WHICH THING IS MISSING, rather than dying with a bare
          `WebSocket is not defined`. This needs a node with a global
          `WebSocket` — measured only on the v26.11.1 this was written on, so
          no version number is claimed here; older node has it behind a flag or
          not at all. The workflow pins a version with `actions/setup-node` for
          the same reason. */
    if (typeof WebSocket === 'undefined') {
        console.error('REFUSED: this node has no global WebSocket, so the browser');
        console.error(`cannot be driven. node here is ${process.version}.`);
        console.error('Use a node that provides a global WebSocket (no package needed).');
        process.exit(2);
    }

    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        console.error('REFUSED: no Chromium-family browser found. Looked at:');
        for (const p of BROWSERS) { console.error(`  ${p}`); }
        console.error('');
        console.error('Set CHROME_PATH to one, or install Chrome, Chromium or Edge.');
        console.error('This test drives a REAL browser; it cannot be faked. It REFUSES');
        console.error('rather than skipping, because a browser-only fault (#768) would');
        console.error('otherwise be guarded by comparing text alone — which is what left');
        console.error('four copies of one rule disagreeing in the first place.');
        process.exit(2);
    }
    console.log(`Browser: ${browserPath}`);

    const work = await mkdtemp(path.join(tmpdir(), 'csrf-carriers-'));
    const docroot = path.join(work, 'docroot');
    const profile = path.join(work, 'profile');
    await rm(docroot, { recursive: true, force: true });
    await import('node:fs/promises').then((fs) => fs.mkdir(docroot, { recursive: true }));

    for (const name of SCRIPTS) {
        await copyFile(path.join(ASSETS, name), path.join(docroot, name));
    }
    await writeFile(path.join(docroot, 'index.html'), PAGE, 'utf8');
    await writeFile(path.join(docroot, 'no-meta.html'), PAGE_NO_META, 'utf8');
    await writeFile(path.join(docroot, 'no-carriers.html'), PAGE_NO_CARRIERS, 'utf8');

    /* 🌐 NODE SERVES THE FIXTURE, NOT `php -S`.
          The first version shelled out to PHP. That works on this machine and
          would have made the test refuse on any runner without a PHP binary —
          for a test that needs no PHP at all, since every file it serves is
          static. One fewer thing that can be missing is one fewer reason for a
          guard to quietly not run (#646).

          Port 0 lets the operating system choose a free one. A fixed or random
          port can collide with something already listening, and a collision
          would look exactly like the browser failing to connect. */
    const types = { '.js': 'text/javascript', '.html': 'text/html; charset=utf-8' };
    const server = createServer(async (request, answer) => {
        const name = path.basename(new URL(request.url, 'http://x').pathname);
        try {
            const body = await readFile(path.join(docroot, name));
            answer.writeHead(200, { 'Content-Type': types[path.extname(name)] ?? 'text/plain' });
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
    ], { stdio: 'ignore' });

    /* ⚠️ WAIT FOR EDGE TO ACTUALLY EXIT BEFORE REMOVING ITS PROFILE.
          Killing it and deleting immediately raced its cache writer and threw
          ENOTEMPTY on `Cache_Data` — AFTER every check had passed, so the run
          reported REFUSED over a successful test. Leftovers here are the
          machine's problem (the repository's own rule about not leaving
          throwaway state behind), so the removal is retried rather than
          skipped, and a failure to tidy is SAID rather than thrown. */
    const stop = async () => {
        const ended = new Promise((resolve) => {
            edge.once('exit', resolve);
            setTimeout(resolve, 5000);
        });
        try { edge.kill('SIGTERM'); } catch { /* already gone */ }
        await ended;
        try { server.close(); } catch { /* already closed */ }
        for (let i = 0; i < 20; i += 1) {
            try {
                await rm(work, { recursive: true, force: true });
                return;
            } catch {
                await new Promise((r) => setTimeout(r, 250));
            }
        }
        console.log(`NOTE: could not remove ${work} — please delete it by hand.`);
    };

    let browser = null;
    try {
        /* ⚠️ READ THE PORT THE BROWSER CHOSE, never assume one.
              `--remote-debugging-port=0` makes it pick a free port and write it
              to `DevToolsActivePort` in its own profile folder — first line the
              port, second line the WebSocket path. Naming a port instead risks
              colliding with whatever is already listening on this machine, and
              a collision looks identical to the browser failing to start. */
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
        if (wsUrl === null) {
            throw new Error(
                `the browser never opened its debugging port (no usable ${portFile} ` +
                `within 20s). Browser: ${browserPath}`
            );
        }

        const socket = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve);
            socket.addEventListener('error', () => reject(new Error('could not attach to Edge')));
        });
        browser = new Browser(socket);

        const target = await browser.send('Target.createTarget', { url: 'about:blank' }, false);
        const attached = await browser.send('Target.attachToTarget',
            { targetId: target.targetId, flatten: true }, false);
        browser.session = attached.sessionId;
        await browser.send('Page.enable');
        await browser.send('Runtime.enable');

        const base = `http://127.0.0.1:${port}`;

        /* ── A. Every carrier, several of each ──────────────────────────── */
        console.log('A. Portal.writeCsrf writes all three carriers');
        await browser.goto(`${base}/index.html`);
        check('A1 the fixture really starts with every carrier at ORIGINAL',
            await browser.evaluate(READ_CARRIERS),
            { meta: 'ORIGINAL', fields: ['ORIGINAL', 'ORIGINAL', 'ORIGINAL'],
              carriers: ['ORIGINAL', 'ORIGINAL', 'ORIGINAL'] });

        check('A2 writeCsrf answers true',
            await browser.evaluate('return window.Portal.writeCsrf("NEW-1");'), true);
        check('A3 all three kinds of carrier, and EVERY element of each, now hold it',
            await browser.evaluate(READ_CARRIERS),
            { meta: 'NEW-1', fields: ['NEW-1', 'NEW-1', 'NEW-1'],
              carriers: ['NEW-1', 'NEW-1', 'NEW-1'] });

        /* ⚠️ A4 AND C2 COMPARE AGAINST A VALUE AN EARLIER CHECK SET, so when
              writeCsrf() is broken they go red alongside A3 for a reason that
              has nothing to do with their own labels (measured: plant 5). They
              are NOT vacuous — each can still fail for its own reason, and the
              `answered` field below is the part A4 is really about — but read
              a failure here together with A3 before believing the label. */
        check('A4 an empty token is refused and nothing is blanked',
            await browser.evaluate('var r = window.Portal.writeCsrf(""); ' + READ_CARRIERS.replace('return {', 'return { answered: r,')),
            { answered: false, meta: 'NEW-1', fields: ['NEW-1', 'NEW-1', 'NEW-1'],
              carriers: ['NEW-1', 'NEW-1', 'NEW-1'] });

        /* ── B. The three answer shapes ─────────────────────────────────── */
        console.log('B. rememberCsrf reads all three answer shapes the portal sends');
        for (const [label, expression, token] of [
            ['B1 envelope meta.csrfToken (ApiResponse)',
             'return window.Portal.rememberCsrf({ meta: { csrfToken: "SHAPE-A" } });', 'SHAPE-A'],
            ['B2 top-level csrfToken (bare JSON handlers)',
             'return window.Portal.rememberCsrf({ csrfToken: "SHAPE-B" });', 'SHAPE-B'],
            ['B3 top-level csrf_token (the two push handlers)',
             'return window.Portal.rememberCsrf({ csrf_token: "SHAPE-C" });', 'SHAPE-C'],
        ]) {
            check(`${label} — answers true`, await browser.evaluate(expression), true);
            check(`${label} — reached every carrier`,
                await browser.evaluate(READ_CARRIERS),
                { meta: token, fields: [token, token, token],
                  carriers: [token, token, token] });
        }

        /* ── C. An answer with no token changes nothing ─────────────────── */
        console.log('C. An answer carrying no token leaves every carrier alone');
        check('C1 answers false',
            await browser.evaluate('return window.Portal.rememberCsrf({ ok: true });'), false);
        check('C2 and nothing was blanked — blanking would make the next post send nothing',
            await browser.evaluate(READ_CARRIERS),
            { meta: 'SHAPE-C', fields: ['SHAPE-C', 'SHAPE-C', 'SHAPE-C'],
              carriers: ['SHAPE-C', 'SHAPE-C', 'SHAPE-C'] });
        check('C3 a non-object answers false too',
            await browser.evaluate('return window.Portal.rememberCsrf(null);'), false);

        /* ── D. The #768 regression itself ──────────────────────────────── */
        console.log('D. A page with NO meta tag still has its form updated (the #768 fault)');
        await browser.goto(`${base}/no-meta.html`);
        check('D1 the fixture really has no meta tag',
            await browser.evaluate('return document.querySelector(\'meta[name="csrf-token"]\') === null;'),
            true);
        check('D2 rememberCsrf answers TRUE — it found a token, which is the question',
            await browser.evaluate('return window.Portal.rememberCsrf({ meta: { csrfToken: "NO-META" } });'),
            true);
        check('D3 and the form field really was written',
            await browser.evaluate(READ_CARRIERS),
            { meta: null, fields: ['NO-META'], carriers: [] });

        /* ── F. Portal.csrf() reads every carrier, and the answer is honest ──
           These two are what pin the round-3 behaviour fix. Without them,
           reverting either half of it left 18/18 green. */
        console.log('F. Portal.csrf() reads beyond the meta tag, and the answer is honest');
        check('F1 on the no-meta page, Portal.csrf() still finds the token in the form',
            await browser.evaluate('return window.Portal.csrf();'), 'NO-META');

        await browser.goto(`${base}/no-carriers.html`);
        check('F2 the fixture really carries nothing',
            await browser.evaluate(READ_CARRIERS),
            { meta: null, fields: [], carriers: [] });
        check('F3 rememberCsrf answers FALSE when there is nowhere to put it — '
            + 'an unconditional true sends a caller down a branch that keeps a spent token',
            await browser.evaluate('return window.Portal.rememberCsrf({ meta: { csrfToken: "X" } });'),
            false);
        check('F4 and Portal.csrf() answers null rather than an empty string',
            await browser.evaluate('return window.Portal.csrf();'), null);

        /* ── E. The other real scripts load beside it without complaint ───
           ⚠️ E2 USED TO READ `check('…', evaluate('return 1;'), 1)` — a clause
              that CANNOT FAIL, which this repository has been caught by often
              enough to name it. It asserted nothing while reading like the
              strongest check in the file. It now collects the browser's own
              error log from the moment the page is created, and any uncaught
              exception or console error from the three real scripts fails it.

           🚨 AND THE COMMENT THAT REPLACED IT WAS ALSO UNTRUE, WHICH IS WORSE
              THAN THE CLAUSE IT REPLACED. It claimed that "deleting the
              delegation from either file, or mistyping `window.Portal`, shows
              up as a real error rather than as silence." **It does not.** This
              test LOADS `event-hub-upload.js` and `report-builder.js` but
              never CALLS `syncCsrf()`, so there is nothing to throw —
              measured by the independent check, which killed the delegation
              three different ways with all 18 checks still passing.

              All four real files are now LOADED (`report-builder.js` was
              missing until round 3 found this comment asserting otherwise), so
              a load-time fault in any of them fails E2. But they still cannot
              be CALLED from here: each file is an IIFE, so `syncCsrf` is not
              reachable from outside it. What guards them is
              check F of `check_one_csrf_writer.py`, which pins each function's
              whole body — a call-site pin would miss an inserted `return;`.
              **Section E proves that all four files load together without
              complaint, and no more than that.** Saying MORE is how a test
              comes to read as coverage it does not provide — and saying LESS,
              as this line did once the fourth file was added, throws away
              cover that was paid for. */
        console.log('E. The three other real scripts load beside portal.js');
        check('E1 window.Portal survived all four scripts loading',
            await browser.evaluate('return typeof window.Portal.writeCsrf;'), 'function');
        check('E2 not one uncaught exception or console error in the whole run',
            browser.errors, []);

    } finally {
        await stop();
    }

    console.log('');
    console.log(`${passed + failed} checks: ${passed} passed, ${failed} failed.`);
    console.log('This does NOT drive the real Host Console; see the header for what it');
    console.log('cannot show.');
    process.exit(failed === 0 ? 0 : 1);
}

main().catch(async (error) => {
    console.error(`REFUSED: ${error.message}`);
    process.exit(2);
});
