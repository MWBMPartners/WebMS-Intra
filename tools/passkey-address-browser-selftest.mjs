/**
 * Path: tools/passkey-address-browser-selftest.mjs
 * -----------------------------------------------------------------------------
 * The name a passkey is tied to, as a REAL browser judges it 🔑🌐🧪 (#903)
 * -----------------------------------------------------------------------------
 * A passkey (WebAuthn) belongs to one name, the "relying party ID" (rpId).
 * The portal sends it when a passkey is made and when one is used, and the
 * BROWSER decides whether that name may be used on the page it is showing.
 * `web/_core/PasskeyAddress.php` decides what the portal sends. Until #903 a
 * stock installation sent an EMPTY rpId, because the setting is seeded empty
 * and was read with `??`. Whether a browser refuses that was stated by the
 * standard and NOT measured; this file measures it, and the other shapes the
 * portal's rule refuses or tidies, so the rule rests on what a browser really
 * does rather than on a reading of the standard.
 *
 * HOW. A small node server answers on 127.0.0.1 and is reached by two names,
 * `localhost` and `127.0.0.1`, so the browser sees a page reached by a NAME
 * and a page reached by a NUMBER. A headless Chromium-family browser is driven
 * over its DevTools protocol (node's own WebSocket; nothing installed), with a
 * VIRTUAL AUTHENTICATOR attached (`WebAuthn.addVirtualAuthenticator`), so a
 * passkey really is made and used with no person and no hardware. Then:
 *
 *   A  what the browser does, measured: an empty rpId is REFUSED for making
 *      and for using a passkey (the #903 fault); a bare name is accepted, and
 *      the authenticator signs SHA-256 of exactly that name and reports the
 *      page's own origin (what WebAuthn::verify*() checks); a port, capitals,
 *      a closing dot, a scheme and a path are each refused; an empty display
 *      name is accepted; a page reached by a NUMBER gets no passkeys at
 *      all, whatever rpId is sent, or none; and (#907) on a page at a name
 *      with a letter outside a-z (bücher.localhost) that name as typed is
 *      refused, and only its "xn--" form is accepted.
 *   B  the portal's rule agrees with the browser: for each value typed into
 *      the setting, `PasskeyAddress::check()` (the real file, run by PHP)
 *      either keeps it — and the browser then ACCEPTS what it keeps — or
 *      refuses it — and the browser refuses the value as typed. So refusing
 *      loses nothing a browser would have taken, and tidying (capitals, a
 *      closing dot) turns a value the browser refuses into one it accepts.
 *   X  plants: a copy of PasskeyAddress.php that KEEPS capitals, one with its
 *      "not a number" refusal removed, one that refuses a good name, and
 *      (#907) one that lets letters outside a-z through, are each judged by
 *      section B, which must go red for each. And one control (a comment
 *      added) must stay green.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It does not run the PORTAL. The portal's pages and its real signature
 *     check are driven by part K of tools/two-step-selftest.php, over HTTP,
 *     with a real P-256 key — but without a browser. Neither file alone shows
 *     a real browser signing in to the real portal; together they show each
 *     half. Driving both at once needs a database and a portal with a NAME
 *     (a browser allows no passkeys at 127.0.0.1, measured here), which this
 *     test does not set up.
 *   - It is one browser engine (Chromium: Edge, Chrome or Chromium). Firefox
 *     and Safari implement the same standard and are not measured here.
 *   - A virtual authenticator is not a phone or a security key. It follows
 *     the same protocol; what it shows is the BROWSER's rules, which is the
 *     question.
 *
 * Usage:  node tools/passkey-address-browser-selftest.mjs
 *         CHROME_PATH chooses the browser; `php` must be on the PATH.
 * Exit:   0 every check passed; 1 a check failed; 2 REFUSED (no browser, no
 *         WebSocket, no php) — never a quiet skip (#646).
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/903
 * -----------------------------------------------------------------------------
 */

import { createServer } from 'node:http';
import { mkdtemp, readFile, writeFile, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawn, spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

/* The same list, and the same reason for it, as tools/csrf-carriers-selftest.mjs:
   any Chromium-family browser, `CHROME_PATH` first, so a Linux runner works. */
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
/* `fileURLToPath`, never `new URL(...).pathname`: this repository's path holds
   spaces and an `&`, which a URL percent-encodes (csrf-carriers' lesson). */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const RULE = path.join(ROOT, 'web', '_core', 'PasskeyAddress.php');

let passed = 0;
let failed = 0;
function check(name, ok, detail = '') {
    if (ok === true) {
        passed += 1;
        console.log(`  ✓ ${name}`);
    } else {
        failed += 1;
        console.log(`  ✗ ${name}`);
        if (detail !== '') { console.log(`      ${detail}`); }
    }
}

const sha = (text) => createHash('sha256').update(text).digest('hex');

/**
 * Ask the real PasskeyAddress::check() (or a planted copy of it) about each
 * value. It uses nothing but PHP, so the file is loaded on its own.
 */
function portalVerdicts(ruleFile, values) {
    const code = 'require $argv[1]; $out = []; foreach (json_decode($argv[2], true) as $v) { '
        + '$c = \\Portal\\Core\\PasskeyAddress::check($v); $out[] = $c["problem"] === "" ? $c["id"] : null; } '
        + 'echo json_encode($out);';
    const run = spawnSync('php', ['-r', code, ruleFile, JSON.stringify(values)], { encoding: 'utf8' });
    if (run.status !== 0) {
        throw new Error(`php could not ask PasskeyAddress::check(): ${run.stderr || run.stdout}`);
    }
    return JSON.parse(run.stdout);
}

async function main() {
    if (typeof WebSocket === 'undefined') {
        console.error(`REFUSED: this node (${process.version}) has no global WebSocket, so the browser cannot be driven.`);
        process.exit(2);
    }
    if (spawnSync('php', ['-v'], { encoding: 'utf8' }).status !== 0) {
        console.error('REFUSED: no `php` on the PATH, so the portal\'s own rule (PasskeyAddress::check) cannot be asked.');
        process.exit(2);
    }
    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        console.error('REFUSED: no Chromium-family browser found. Set CHROME_PATH, or install Chrome, Chromium or Edge.');
        console.error('This test measures what a REAL browser does; it cannot be faked, so it refuses rather than skipping.');
        process.exit(2);
    }
    console.log(`Browser: ${browserPath}`);

    const work = await mkdtemp(path.join(tmpdir(), 'passkey-address-'));
    const profile = path.join(work, 'profile');
    // One plain page; the rpId questions are asked from script on it.
    const server = createServer((request, answer) => {
        answer.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        answer.end('<!doctype html><html lang="en"><head><meta charset="utf-8"><title>passkey address</title></head><body>ok</body></html>');
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    const edge = spawn(browserPath, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu'], { stdio: 'ignore' });
    /* Wait for the browser to EXIT before removing its profile (csrf-carriers'
       ENOTEMPTY lesson), and say so rather than throw when tidying fails. */
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
        /* Read the port the browser CHOSE (DevToolsActivePort), never name one. */
        let wsUrl = null;
        for (let i = 0; i < 200 && wsUrl === null; i += 1) {
            try {
                const chosen = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0].trim();
                wsUrl = (await (await fetch(`http://127.0.0.1:${chosen}/json/version`)).json()).webSocketDebuggerUrl;
            } catch { await new Promise((r) => setTimeout(r, 100)); }
        }
        if (wsUrl === null) { throw new Error('the browser never opened its debugging port within 20s'); }
        const socket = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve);
            socket.addEventListener('error', () => reject(new Error('could not attach to the browser')));
        });
        let next = 1;
        let session = null;
        const waiting = new Map();
        socket.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            const resolver = waiting.get(msg.id);
            if (resolver !== undefined) { waiting.delete(msg.id); resolver(msg); }
        });
        const send = (method, params = {}, inSession = true) => new Promise((resolve, reject) => {
            const id = next;
            next += 1;
            const frame = { id, method, params };
            if (inSession === true && session !== null) { frame.sessionId = session; }
            waiting.set(id, (msg) => (msg.error !== undefined ? reject(new Error(`${method}: ${msg.error.message}`)) : resolve(msg.result)));
            socket.send(JSON.stringify(frame));
        });
        const target = await send('Target.createTarget', { url: 'about:blank' }, false);
        session = (await send('Target.attachToTarget', { targetId: target.targetId, flatten: true }, false)).sessionId;
        await send('Page.enable');
        await send('WebAuthn.enable');
        await send('WebAuthn.addVirtualAuthenticator', { options: {
            protocol: 'ctap2', transport: 'internal', hasResidentKey: true,
            hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
        } });
        const product = (await send('Browser.getVersion', {}, false)).product;
        console.log(`Engine: ${product}`);

        /* Open a page and WAIT until it is that page: Page.navigate resolves when
           navigation starts, and a question asked of the previous page would be
           judged against the wrong origin. A poll, not a sleep (#735). */
        const at = async (url) => {
            await send('Page.navigate', { url });
            for (let i = 0; i < 100; i += 1) {
                const r = await send('Runtime.evaluate', { expression: 'document.readyState + "|" + location.href', returnByValue: true });
                if (r.result.value === `complete|${url}`) { return; }
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
            throw new Error(`${url} never finished loading`);
        };
        /* Run an async snippet on the page; an exception comes back as {error}. */
        const run = async (body) => {
            const r = await send('Runtime.evaluate', {
                expression: `(async () => { try { ${body} } catch (e) { return { error: e.name, message: e.message }; } })()`,
                awaitPromise: true, returnByValue: true,
            });
            return r.result.value;
        };
        const hexOf = 'Array.from(b).map((v) => v.toString(16).padStart(2, "0")).join("")';
        /* Make a passkey with this rp (id left out when undefined). */
        const make = (rpId, rpName = 'Portal') => run(`
            const c = await navigator.credentials.create({ publicKey: {
                challenge: crypto.getRandomValues(new Uint8Array(32)),
                rp: { ${rpId === undefined ? '' : `id: ${JSON.stringify(rpId)}, `}name: ${JSON.stringify(rpName)} },
                user: { id: crypto.getRandomValues(new Uint8Array(16)), name: 'member@example.org', displayName: 'Member' },
                pubKeyCredParams: [{ type: 'public-key', alg: -7 }], timeout: 5000, attestation: 'none' } });
            const client = JSON.parse(new TextDecoder().decode(c.response.clientDataJSON));
            const b = new Uint8Array(c.response.getAuthenticatorData()).slice(0, 32);
            return { ok: true, id: c.id, origin: client.origin, rpIdHash: ${hexOf} };`);
        /* Use the passkey with this credential id (so no chooser can appear). */
        const use = (rpId, credId) => run(`
            const raw = Uint8Array.from(atob(${JSON.stringify(credId)}.replace(/-/g, '+').replace(/_/g, '/')
                + '='.repeat((4 - ${JSON.stringify(credId)}.length % 4) % 4)), (ch) => ch.charCodeAt(0));
            const c = await navigator.credentials.get({ publicKey: {
                challenge: crypto.getRandomValues(new Uint8Array(32)),
                ${rpId === undefined ? '' : `rpId: ${JSON.stringify(rpId)}, `}
                allowCredentials: [{ type: 'public-key', id: raw }], timeout: 5000 } });
            const client = JSON.parse(new TextDecoder().decode(c.response.clientDataJSON));
            const b = new Uint8Array(c.response.authenticatorData).slice(0, 32);
            return { ok: true, origin: client.origin, rpIdHash: ${hexOf} };`);
        const refused = (answer) => answer !== null && typeof answer === 'object' && answer.error === 'SecurityError';
        const show = (answer) => JSON.stringify(answer);

        const named = `http://localhost:${port}/`;
        const numbered = `http://127.0.0.1:${port}/`;
        /* #907: a page at a name with a letter outside a-z. "bücher.localhost" is
           sent by the browser as its ASCII ("xn--") form, and a name under
           localhost is a secure page with no certificate and needs no address
           book entry, which is why it stands in for any such name here. */
        const idn = `http://xn--bcher-kva.localhost:${port}/`;

        /* ── A. What the browser does ──────────────────────────────────── */
        console.log('A. What a real browser does with each rpId');
        await at(named);
        const empty = await make('');
        check('A1 an EMPTY rpId — what a stock installation sent before #903 — is REFUSED when making a passkey', refused(empty), show(empty));
        const good = await make('localhost');
        check('A2 the bare name of the page (localhost) is accepted; the authenticator signs SHA-256 of exactly that name, and reports the page\'s own origin',
            good.ok === true && good.rpIdHash === sha('localhost') && good.origin === `http://localhost:${port}`, show(good));
        const emptyUse = good.ok === true ? await use('', good.id) : { skipped: 'A2 made no passkey' };
        check('A3 an EMPTY rpId is REFUSED when USING a passkey too (the sign-in half of the #903 fault)', refused(emptyUse), show(emptyUse));
        const goodUse = good.ok === true ? await use('localhost', good.id) : { skipped: 'A2 made no passkey' };
        check('A4 and the passkey made under "localhost" signs in under "localhost": same name signed, same origin',
            goodUse.ok === true && goodUse.rpIdHash === sha('localhost') && goodUse.origin === `http://localhost:${port}`, show(goodUse));
        const shapes = {
            [`localhost:${port}`]: 'a port (what the old fallback kept from the Host header)',
            LOCALHOST: 'capitals',
            'localhost.': 'a closing dot',
            'http://localhost': 'a scheme',
            'localhost/sign-in': 'a path',
        };
        let n = 5;
        for (const [shape, what] of Object.entries(shapes)) {
            const answer = await make(shape);
            check(`A${n} ${what} ("${shape}") is REFUSED`, refused(answer), show(answer));
            n += 1;
        }
        const noName = await make('localhost', '');
        check(`A${n} an EMPTY display name (rp.name, also seeded empty) is ACCEPTED — so that one was only ever cosmetic`, noName.ok === true, show(noName));
        n += 1;
        await at(numbered);
        const byNumber = await make('127.0.0.1');
        const byNumberNone = await make(undefined);
        check(`A${n} on a page reached by a NUMBER (127.0.0.1) no passkey can be made at all: refused with the number as the rpId, and with none`,
            refused(byNumber) && refused(byNumberNone), `${show(byNumber)} / ${show(byNumberNone)}`);
        n += 1;
        /* #907: what the code before #903 did with a stored name holding a letter
           outside a-z. It sent the setting AS TYPED as the rpId (git show
           7a666338^:web/_apps/auth/login/webauthn.php), so this asks the browser
           about exactly that, on a page at that very name: the name as typed is
           REFUSED, making and using alike, and only its "xn--" form is accepted,
           signed as SHA-256 of the xn-- form. So no passkey can have been made
           under such a setting, and PasskeyAddress ignoring it now strands none. */
        await at(idn);
        const idnTyped = await make('bücher.localhost');
        const idnAscii = await make('xn--bcher-kva.localhost');
        const idnTypedUse = idnAscii.ok === true ? await use('bücher.localhost', idnAscii.id) : { skipped: 'no passkey made under the xn-- form' };
        check(`A${n} on a page at bücher.localhost, the name TYPED with its ü (what the old code sent) is REFUSED for making and for using a passkey; its "xn--" form is accepted and signed as exactly that form`,
            refused(idnTyped) && refused(idnTypedUse) && idnAscii.ok === true && idnAscii.rpIdHash === sha('xn--bcher-kva.localhost')
                && idnAscii.origin === `http://xn--bcher-kva.localhost:${port}`,
            `${show(idnTyped)} / ${show(idnTypedUse)} / ${show(idnAscii)}`);

        /* ── B. The portal's rule agrees with the browser ──────────────── */
        console.log('B. PasskeyAddress::check() keeps only what the browser accepts, and refuses only what it refuses');
        /* Each value as an administrator might type it, and the page it is judged on. */
        const typed = [
            ['localhost', named], ['LOCALHOST', named], ['localhost.', named], ['  localhost ', named],
            [`localhost:${port}`, named], ['http://localhost', named], ['localhost/sign-in', named],
            ['me@localhost', named], ['', named], ['127.0.0.1', numbered],
            // #907: a letter outside a-z is refused by the rule, and must be by the browser too; its xn-- form kept, and accepted.
            ['bücher.localhost', idn], ['xn--bcher-kva.localhost', idn],
        ];
        const judge = async (ruleFile) => {
            const verdicts = portalVerdicts(ruleFile, typed.map(([value]) => value));
            const wrong = [];
            for (let i = 0; i < typed.length; i += 1) {
                const [value, page] = typed[i];
                const kept = verdicts[i];
                await at(page);
                if (kept !== null && kept !== '') {
                    const answer = await make(kept);
                    if (answer.ok !== true) { wrong.push(`kept ${JSON.stringify(value)} as ${JSON.stringify(kept)}, which the browser REFUSES: ${show(answer)}`); }
                } else {
                    // Refused, or empty (= "not set"): the browser must refuse the value as typed.
                    const answer = await make(value);
                    if (refused(answer) === false) { wrong.push(`${kept === '' ? 'treated as not set' : 'refused'} ${JSON.stringify(value)}, which the browser ACCEPTS: ${show(answer)}`); }
                }
            }
            return { verdicts, wrong };
        };
        const real = await judge(RULE);
        check(`B1 for all ${typed.length} typed values, the real rule and the browser agree (capitals and a closing dot are tidied into a name the browser accepts; everything refused, the browser refuses too)`,
            real.wrong.length === 0, `${real.wrong.join(' | ')} — verdicts ${JSON.stringify(real.verdicts)}`);
        const tidied = real.verdicts[1] === 'localhost' && real.verdicts[2] === 'localhost' && real.verdicts[3] === 'localhost';
        check('B2 and the tidying is really exercised: "LOCALHOST", "localhost." and "  localhost " are each KEPT as localhost (a premise for B1 meaning anything)',
            tidied, JSON.stringify(real.verdicts));

        /* ── X. Plants ─────────────────────────────────────────────────── */
        console.log('X. Plants: a broken copy of the rule must turn B1 red');
        const source = await readFile(RULE, 'utf8');
        /* Each plant is one or more exact swaps, each required to match ONCE,
           written to a copy outside the repository and judged by section B. */
        const plant = async (label, swaps, shouldFail) => {
            let planted = source;
            for (const [from, to] of swaps) {
                if (planted.split(from).length !== 2) { throw new Error(`plant "${label}" matched ${planted.split(from).length - 1} places, not one`); }
                planted = planted.replace(from, to);
            }
            const file = path.join(work, `PasskeyAddress-${label}.php`);
            await writeFile(file, planted, 'utf8');
            const verdict = await judge(file);
            return shouldFail === true ? verdict.wrong.length > 0 : verdict.wrong.length === 0;
        };
        /* ⚠️ X1 TAKES TWO SWAPS, and the first version took one. Dropping only
              the lower-casing left B1 GREEN (measured): the name pattern then
              refuses "LOCALHOST", and the browser refuses it too, so the two
              still agree. The fault worth catching is the one that KEEPS a
              value the browser refuses — here, "simplifying" the lower-casing
              into a pattern that ignores capitals. */
        check('X1 PLANT, capitals matched instead of tidied: "LOCALHOST" is KEPT as typed and the browser refuses it, so B1 can fail',
            await plant('capitals-kept', [
                ["$id = rtrim(strtolower($text), '.');", "$id = rtrim($text, '.');"],
                ["'(?:\\.' . self::HOST_LABEL . ')*$/', $id)", "'(?:\\.' . self::HOST_LABEL . ')*$/i', $id)"],
            ], true));
        check('X2 PLANT, the "not a number" refusal removed: 127.0.0.1 is kept and the browser refuses it, so B1 can fail',
            await plant('ip-allowed', [["if (preg_match('/^[0-9.]+$/', $id) === 1) {", 'if (false) {']], true));
        check('X3 PLANT, the other direction — a refusal that also catches a good name: "localhost" is refused although the browser accepts it, so B1 can fail',
            await plant('refuses-all', [["if (str_contains($text, '://') === true) {", 'if (true) {']], true));
        /* #907: the other half of the "letters outside a-z" claim. A rule that
           let such a letter through would KEEP "bücher.localhost" as typed, and
           the browser refuses it (A12), so B1 must go red. Bytes 0x80-0xFF are
           let into each part of the name, which is what any UTF-8 letter is. */
        check('X5 PLANT, letters outside a-z let through: "bücher.localhost" is KEPT as typed and the browser refuses it, so B1 can fail',
            await plant('idn-kept', [["private const HOST_LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';",
                "private const HOST_LABEL = '[a-z0-9\\x80-\\xff](?:[a-z0-9\\x80-\\xff-]{0,61}[a-z0-9\\x80-\\xff])?';"]], true));
        check('X4 CONTROL, a comment added: B1 stays green, so a red B1 above means the rule changed, not the harness',
            await plant('comment', [["$id = rtrim(strtolower($text), '.');", "// p903 control\n        $id = rtrim(strtolower($text), '.');"]], false));
    } finally {
        await stop();
    }

    console.log('');
    console.log(`${passed + failed} checks: ${passed} passed, ${failed} failed.`);
    console.log('This does NOT drive the portal itself; see the header for what it cannot show.');
    process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => {
    console.error(`REFUSED: ${error.message}`);
    process.exit(2);
});
