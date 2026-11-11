/**
 * Path: tools/public-door-script-selftest.mjs
 * -----------------------------------------------------------------------------
 * The public door's SCRIPT, and its FRAMED PAGE, driven in a REAL browser 🚪🌐🧪
 * (#493 packages P4b and P4c; D-056, D-057, D-082, D-084, D-094)
 * -----------------------------------------------------------------------------
 * `web/_core/data/public-door.js` is what an organisation pastes into its own
 * website. Whether it draws TEXT ONLY, stays inside its shadow root and obeys
 * a website's Content-Security-Policy is BEHAVIOUR, and behaviour is driven,
 * not read (D-065): no reading of the file's source can tell a harmless edit
 * from one that turns a title into live markup. So this serves the REAL file
 * to a real headless Chromium-family browser and looks at what it does.
 *
 * HOW. One small node server answers on 127.0.0.1 and is reached by TWO
 * names, so the browser sees two websites:
 *   http://127.0.0.1:{port}/site/…   "the organisation's website" — pages
 *       sent with a STRICT Content-Security-Policy (no inline style, no
 *       eval, scripts and data only from the portal's address, a nonce for
 *       the test's own probe), which reports every violation to the page;
 *   http://localhost:{port}/w/{name}/…  "the portal" — the real script, and
 *       feeds made up for each case, answered with the door's own headers
 *       (CORS *, nosniff, text/javascript). The portal's real gate and real
 *       feeds are tools/public-door-selftest.php's job, over HTTP.
 * The browser runs with its clock zone set to Pacific/Kiritimati (UTC+14), so
 * a time converted to the visitor's zone would come out visibly wrong.
 *
 * WHAT IT COVERS (names below are the checks)
 *   E  events: EVERY drawn field (title, description, place, address, the
 *      zone label; and in the other copies the poster's title, kicker, place
 *      and time, and the countdown's title) carries markup, and each appears
 *      as the very characters sent (E09) and NOTHING runs; a link only to
 *      https:, opened away, telling the other side nothing; a time on the
 *      event's OWN clock, its zone named only where it differs from the
 *      organisation's (E06, E07); and inside the hour a clock skips, the
 *      reading as STORED is shown, not the moved instant (E08, #777).
 *   P  posters: pictures only from https:, no referrer; "Every Tuesday";
 *      a javascript: link dropped; a second copy on the same page (another
 *      organisation, drawn into a named place) does not cross with the first.
 *   N  "next" (the countdown, D-084): the soonest by REAL instant across two
 *      clocks, not the feed's order; EVERY box (2 days, 03 hours, 25 minutes,
 *      and the seconds moving); an event under way says "Happening now".
 *   O  the door off (its feed refuses): nothing drawn, no error; an empty
 *      feed: "No upcoming events."
 *   I  isolation: exactly one empty element added to the website per copy,
 *      with no style attribute; nothing added to the page's <head>; no new
 *      stylesheet in the page; the page's own styles do not reach in and the
 *      script's do not leak out; no new global variable; NOT ONE
 *      Content-Security-Policy violation (no inline style, no eval); no
 *      uncaught error or console error.
 *   R  what the portal received: the feed was asked for with NO Referer, and
 *      the limit asked for. (No cookie half: see the note above R01.)
 *   F  (#493 P4c) the page a website shows in a FRAME, drawn by the REAL
 *      renderer and sent with the REAL headers (PHP makes both from
 *      web/_core for the run), over https: opened on its own every hostile
 *      value is text and its one stylesheet is allowed by its hash (F01); a
 *      website the organisation LISTS frames it (F02); a website NOT listed,
 *      framing the same page with the same headers, is REFUSED by the
 *      browser (F03); with nothing listed even that website is refused (F04);
 *      no Referer is sent (F05). FX1-FX5 break what is served — frame-
 *      ancestors widened to "*", the stylesheet's hash, the title unescaped
 *      (and then with every wall down, so "nothing ran" is shown to be able
 *      to fail) — with one control that must stay green.
 *   X  the plants: each rule broken in a copy of the script served in its
 *      place, and the check that must notice required to go red — innerHTML
 *      on each drawn field ON ITS OWN (X14-X19), the zone label on every
 *      event (X20), the shown time taken from the instant (X21), and each
 *      countdown box (X22-X25) among them; one control edit (a comment)
 *      required to stay green.
 *
 * WHAT IT CANNOT DO, said plainly
 *   - It serves made-up feeds, not the portal's: the gate, the headers and
 *     what the feeds contain are tools/public-door-selftest.php's. The framed
 *     page is the REAL renderer's output for a made-up fixture, sent with the
 *     real header lines; that the door really sends those lines, with
 *     frameAncestorsFor()'s answer in each state, is that test's section V.
 *   - Old browsers that ignore frame-ancestors and honour only
 *     X-Frame-Options are not run.
 *   - One browser engine (Chromium-family). Firefox and Safari are not run.
 *   - It cannot see a website builder that rewrites pasted scripts.
 *
 * Usage:  node tools/public-door-script-selftest.mjs
 *         Needs a Chromium-family browser (CHROME_PATH, or the usual places),
 *         a node with a global WebSocket, and — since #493 P4c — PHP with its
 *         openssl extension (PHP_BINARY, or `php` on the path), which draws
 *         the framed page from web/_core and makes section F's certificate.
 *         REFUSES (exit 2) rather than skipping when any is missing.
 *
 * Exit:   0 only when every check passed AND the number of checks is the
 *         number expected; 1 otherwise; 2 when it refused to run.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/493
 * -----------------------------------------------------------------------------
 */

import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:http';
import { createServer as createHttpsServer } from 'node:https';
import { readFile, writeFile, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

/** How many checks a full run makes; a different number means a block was skipped or ran twice. */
const EXPECTED_CHECKS = 66; // #882 adds N05 and X08b; 39 in the first build; round 1 of P4b's check added E07-E09 and X14-X25; #493 P4c added F01-F05 and FX1-FX5

/* Any Chromium-family browser (see tools/csrf-carriers-selftest.mjs for why). */
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
/* ⚠️ fileURLToPath, never URL.pathname: this repository's path holds spaces and an "&". */
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT_FILE = path.join(ROOT, 'web', '_core', 'data', 'public-door.js');

let passed = 0;
let failed = 0;
function check(name, got, want) {
    if (JSON.stringify(got) === JSON.stringify(want)) {
        passed += 1;
        console.log(`  ✓ ${name}`);
        return;
    }
    failed += 1;
    console.log(`  ✗ ${name}`);
    console.log(`      wanted: ${JSON.stringify(want)}`);
    console.log(`      got:    ${JSON.stringify(got)}`);
}

/* ── Times for the made-up feeds ─────────────────────────────────────────── */
/** An instant as the door writes it: the reading on `zone`'s clock plus that clock's offset. */
function stampIn(ms, zone) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
        second: '2-digit', hourCycle: 'h23', timeZoneName: 'longOffset',
    }).formatToParts(new Date(ms)).map((p) => [p.type, p.value]));
    const off = parts.timeZoneName === 'GMT' ? '+00:00' : parts.timeZoneName.replace('GMT', '');
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}${off}`;
}

/* ⚠️ HOSTILE TEXT IN EVERY FIELD THE SCRIPT DRAWS — round 1 of P4b's check.
      The first version put markup only in the event's title, description and
      place name and the poster's title and link; the poster's kicker,
      location and time and the event's address were plain words. So putting
      innerHTML on ONE of those fields alone left every check green: the shared
      `el()` plant (X01) could not see a field-by-field fault. Each field below
      now carries its own markup, MISSING_* lists them, and X14-X19 break each
      field on its own. (The zone is drawn too, as "(<place> time)", so it has
      one; it is written without a "/" because the label takes the part after
      the last one.) */
const EVIL_TITLE = '<img src=x onerror="window.__pwned=1">Evil & "quotes" </script>';
const EVIL_ABOUT = '<script>window.__pwned=2</script><b>bold?</b>';
const EVIL_POSTER = '<svg onload="window.__pwned=4"></svg>Toddlers';
const EVIL_PLACE_NAME = '<b>Hall</b>';
const EVIL_ADDRESS = '<i onclick="window.__pwned=9">1 Street</i>';
// No "_" in it either: the label turns underscores into spaces ("New_York").
const EVIL_ZONE_PLACE = '<img src=x onerror=window.pwnedSix=1>Nowhere';
const EVIL_KICKER = '<u onmouseover="window.__pwned=7">Weekly</u>';
const EVIL_POSTER_PLACE = '<script>window.__pwned=8</script>Hall';
const EVIL_TIME = '<b>10:30</b>';
const EVIL_NEXT = '<em>New York sooner</em>';
/** Every hostile value that must appear, character for character, in each copy's text. */
const MUST_APPEAR = {
    events: [EVIL_TITLE, EVIL_ABOUT, EVIL_PLACE_NAME, EVIL_ADDRESS, `(${EVIL_ZONE_PLACE} time)`],
    posters: [EVIL_POSTER, EVIL_KICKER, EVIL_POSTER_PLACE, EVIL_TIME],
    next: [EVIL_NEXT],
};

/** How far ahead the "next" event is: 2 days, 3 hours, 25 minutes and 30 seconds. */
const NEXT_IN_MS = ((2 * 24 + 3) * 3600 + 25 * 60 + 30) * 1000;

function feeds() {
    const now = Date.now();
    const org = (zone) => ({ name: 'Made-up organisation <i>', timezone: zone });
    // startsOnClock is the reading as stored (#493 P4b): the same reading as
    // startsAt's, without its offset, except inside the hour a clock skips.
    const ev = (title, startMs, endMs, zone, extra = {}) => ({
        id: title.toLowerCase().replace(/[^a-z]+/g, '-'), title, startsAt: stampIn(startMs, zone),
        endsAt: endMs === null ? null : stampIn(endMs, zone), startsOnClock: stampIn(startMs, zone).slice(0, 19),
        endsOnClock: endMs === null ? null : stampIn(endMs, zone).slice(0, 19), allDay: false, timezone: zone, detailsLimited: false,
        description: null, location: null, ...extra,
    });
    const events = (zone, list) => ({ format: 'webms-public-events', version: 1, organisation: org(zone), generatedAt: new Date().toISOString(), events: list });
    return {
        EVENTSAAAAAAAAAAAAAAAAAAA: events('Europe/London', [
            {
                id: 'evil', title: EVIL_TITLE, startsAt: '2030-06-01T10:30:00-04:00', endsAt: '2030-06-01T12:00:00-04:00',
                startsOnClock: '2030-06-01T10:30:00', endsOnClock: '2030-06-01T12:00:00',
                allDay: false, timezone: 'America/New_York', detailsLimited: false, description: EVIL_ABOUT,
                location: { name: EVIL_PLACE_NAME, address: EVIL_ADDRESS, url: 'javascript:window.__pwned=3' },
            },
            ev('Https venue', now + 86400000, null, 'Europe/London', { location: { name: 'Venue', address: null, url: 'https://venue.example.invalid/' } }),
            ev('Plain venue', now + 2 * 86400000, null, 'Europe/London', { location: { name: 'Plain', address: null, url: 'http://plain.example.invalid/' } }),
            {
                id: 'zone', title: 'Zone markup', startsAt: '2030-07-01T09:00:00+00:00', endsAt: null,
                startsOnClock: '2030-07-01T09:00:00', endsOnClock: null, allDay: false, timezone: `Atlantic/${EVIL_ZONE_PLACE}`,
                detailsLimited: false, description: null, location: null,
            },
            // The morning London's clocks go forward: stored at 01:30, which
            // does not exist, so its INSTANT has moved to 02:30+01:00. A
            // website must SHOW 01:30, as the portal's own pages do (#777).
            {
                id: 'gap', title: 'Missing hour', startsAt: '2031-03-30T02:30:00+01:00', endsAt: '2031-03-30T03:00:00+01:00',
                startsOnClock: '2031-03-30T01:30:00', endsOnClock: '2031-03-30T03:00:00', allDay: false, timezone: 'Europe/London',
                detailsLimited: false, description: null, location: null,
            },
        ]),
        POSTERSBBBBBBBBBBBBBBBBBB: {
            format: 'webms-public-posters', version: 1, organisation: org('Europe/London'), generatedAt: new Date().toISOString(),
            posters: [
                { title: EVIL_POSTER, kicker: EVIL_KICKER, category: null, when: { type: 'weekly', weekday: 2 }, time: EVIL_TIME, location: EVIL_POSTER_PLACE,
                    link: 'javascript:window.__pwned=5', media: { type: 'image', url: 'https://pictures.example.invalid/a.png' }, thumbUrl: null, canvaUrl: null },
                { title: 'Second poster', kicker: null, category: null, when: null, time: null, location: null,
                    link: 'https://more.example.invalid/', media: { type: 'image', url: 'http://pictures.example.invalid/plain.png' }, thumbUrl: null, canvaUrl: null },
            ],
        },
        // The NEXT case: listed in the feed's own order Auckland FIRST (as a
        // feed sorted by clock reading would), though New York's starts
        // sooner by the real instant.
        // ⚠️ Round 1 of P4b's check: the event was two hours away, so the
        //    days box read 0 and the minutes box was never compared, and
        //    `left / 60` for the minutes (~"119") stayed green. Now it is 2
        //    days, 3 hours and 25 minutes (and a half) away, so every box
        //    holds a value no wrong formula gives.
        NEXTCCCCCCCCCCCCCCCCCCCCC: events('Europe/London', [
            ev('Auckland later', now + 5 * 86400000, now + 5 * 86400000 + 3600000, 'Pacific/Auckland'),
            ev(EVIL_NEXT, now + NEXT_IN_MS, now + NEXT_IN_MS + 3600000, 'America/New_York'),
        ]),
        NOWDDDDDDDDDDDDDDDDDDDDDD: events('Europe/London', [
            ev('Under way', now - 3600000, now + 3600000, 'Europe/London'),
            ev('Tomorrow', now + 86400000, null, 'Europe/London'),
        ]),
        EMPTYEEEEEEEEEEEEEEEEEEEE: events('Europe/London', []),
        // #882 (D-129): an event with NO end time that started two hours ago
        // and that the door still lists for three more hours (its
        // `listedUntil`). The countdown must follow the door's list and say it
        // is under way. The script before #882 ended it at its START, so it
        // skipped to "Later today". A no-end event whose listedUntil has
        // passed is gone either way.
        NOENDFFFFFFFFFFFFFFFFFFFF: events('Europe/London', [
            ev('Gone, no end time', now - 5 * 3600000, null, 'Europe/London', { listedUntil: stampIn(now - 3600000, 'Europe/London') }),
            ev('Still on, no end time', now - 2 * 3600000, null, 'Europe/London', { listedUntil: stampIn(now + 3 * 3600000, 'Europe/London') }),
            ev('Later today', now + 4 * 3600000, now + 5 * 3600000, 'Europe/London'),
        ]),
    };
}

/* ── The organisation's website: strict CSP, a nonce'd probe, the pasted lines ── */
const NONCE = 'selftestnonce';
function sitePage(port, body) {
    const portal = `http://localhost:${port}`;
    return {
        csp: `default-src 'none'; script-src 'nonce-${NONCE}' ${portal}; connect-src ${portal}; img-src https: ${portal}; `
            + `style-src 'self'; base-uri 'none'; form-action 'none'`,
        html: `<!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><title>made-up website</title>
<link rel="stylesheet" href="/site/site.css">
<script nonce="${NONCE}">
window.__violations = [];
document.addEventListener('securitypolicyviolation', function (e) { window.__violations.push(e.violatedDirective + ' ' + e.blockedURI); });
window.__before = { keys: Object.keys(window), head: document.head.children.length };
</script>
</head><body>
<p id="mine">The website's own paragraph.</p>
<p class="door__title" id="leak">A paragraph of the website's own that shares a class name with the script.</p>
${body.replace(/PORTAL/g, portal)}
</body></html>`,
    };
}
const SITE_CSS = 'p { color: rgb(255, 0, 0); font-family: monospace; }\n';

/* ── #493 P4c: the page a website shows in a FRAME ───────────────────────────
   The page is drawn by the REAL renderer (PublicDoor::renderPage()) and sent
   with the REAL headers (PublicDoor::pageHeaderLines(), with frame-ancestors
   from PublicWebsiteSetup::frameAncestorsValue() — the function
   frameAncestorsFor() hands the organisation's list to), each produced by PHP
   from web/_core for this run. Over https, because frameAncestorsValue()
   accepts only https websites and an https website may not frame an http
   page; three made-up names share one address and one certificate the test
   makes itself. Each is its OWN site (three different second-level names, not
   three names under one): two names under one ".test" name are ONE site to the
   browser, so a frame between them runs in the same process and is not
   announced as a target of its own — measured, FX4 saw no frame at all:
     https://portal-selftest.test:{port}    "the portal", serving the page;
     https://listed-selftest.test:{port}    a website the organisation LISTS;
     https://unlisted-selftest.test:{port}  a website it does not.
   The page draws its words as TEXT and runs no script, so the hostile values
   are the same kind as above. */
const FRAME_NAME = 'FRAMEPAGEAAAAAAAAAAAAAAAA';
const FRAME_TITLE = '<img src=x onerror="window.__pwned=11">Framed & "quoted"';
const FRAME_ABOUT = '<script>window.__pwned=12</script>about';
const FRAME_POSTER = '<svg onload="window.__pwned=13"></svg>poster';
const FRAME_MUST_APPEAR = [FRAME_TITLE, FRAME_ABOUT, FRAME_POSTER];
const FRAME_FIXTURE = {
    events: { organisation: { name: 'Made-up organisation </title><i>', timezone: 'Europe/London' }, events: [{
        id: 'framed', title: FRAME_TITLE, startsAt: '2030-06-01T10:30:00+01:00', endsAt: null, startsOnClock: '2030-06-01T10:30:00',
        endsOnClock: null, allDay: false, timezone: 'Europe/London', detailsLimited: false, description: FRAME_ABOUT,
        location: { name: 'Hall', address: null, url: 'https://venue.example.invalid/' },
    }] },
    posters: { organisation: { name: 'Made-up organisation </title><i>', timezone: 'Europe/London' }, posters: [{
        title: FRAME_POSTER, kicker: null, category: null, when: null, time: null, location: null, link: 'https://more.example.invalid/',
        media: null, thumbUrl: null, canvaUrl: null,
    }] },
};
/* The PHP that answers for the portal's half. `cert` makes a certificate;
   otherwise it prints the page and the two header sets as JSON. */
const FRAME_PHP = String.raw`<?php
declare(strict_types=1);
$core = $argv[1];
spl_autoload_register(static function (string $c) use ($core): void {
    if (str_starts_with($c, 'Portal\\Core\\') === true) {
        $f = $core . '/' . substr($c, 12) . '.php';
        if (is_file($f) === true) {
            require $f;
        }
    }
});
if (($argv[2] ?? '') === 'cert') {
    if (function_exists('openssl_pkey_new') === false) {
        fwrite(STDERR, "this PHP has no openssl extension\n");
        exit(3);
    }
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'portal-selftest.test'], $key, ['digest_alg' => 'sha256']);
    $crt = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($crt, $certText);
    openssl_pkey_export($key, $keyText);
    echo json_encode(['cert' => $certText, 'key' => $keyText]);
    exit(0);
}
$fx = json_decode($argv[4], true);
echo json_encode([
    'page'   => \Portal\Core\PublicDoor::renderPage($fx['events'], $fx['posters']),
    'listed' => \Portal\Core\PublicDoor::pageHeaderLines(\Portal\Core\PublicWebsiteSetup::frameAncestorsValue([$argv[3]]), false, false),
    'none'   => \Portal\Core\PublicDoor::pageHeaderLines(\Portal\Core\PublicWebsiteSetup::frameAncestorsValue([]), false, false),
]);
`;
/** What a framed (or directly opened) page shows, read INSIDE it. */
const FRAME_PROBE = `var t = document.querySelector('.door__title');
    return { url: document.URL, ready: document.readyState, text: document.body ? document.body.textContent : '',
        pwned: window.__pwned === undefined ? null : window.__pwned, scripts: document.scripts.length,
        weight: t ? getComputedStyle(t).fontWeight : null,
        links: Array.prototype.map.call(document.querySelectorAll('a'), function (a) { return { href: a.getAttribute('href'), target: a.target, rel: a.rel }; }) };`;

/* ── A very small DevTools client (the csrf-carriers test's, trimmed) ─────── */
class Browser {
    constructor(ws) {
        this.ws = ws;
        this.next = 1;
        this.waiting = new Map();
        this.session = null;
        this.errors = [];
        this.warnings = [];
        this.frames = [];
        ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            if (msg.method === 'Runtime.exceptionThrown') {
                const d = msg.params?.exceptionDetails ?? {};
                this.errors.push(`uncaught: ${d.exception?.description ?? d.text ?? 'unknown'}`);
                return;
            }
            // #493 P4c: a frame from another website is a separate target
            // (an "out-of-process" frame); auto-attach announces each one, and
            // section F evaluates inside it through its own session.
            if (msg.method === 'Target.attachedToTarget') {
                this.frames.push({ session: msg.params.sessionId, type: msg.params.targetInfo?.type ?? '', url: msg.params.targetInfo?.url ?? '' });
                return;
            }
            if (msg.method === 'Runtime.consoleAPICalled') {
                const words = (msg.params.args ?? []).map((a) => a.value ?? a.description ?? '?').join(' ');
                if (msg.params?.type === 'error') { this.errors.push(`console.error: ${words}`); }
                if (msg.params?.type === 'warning') { this.warnings.push(words); }
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
        if (typeof useSession === 'string') { frame.sessionId = useSession; } else if (useSession === true && this.session !== null) { frame.sessionId = this.session; }
        return new Promise((resolve, reject) => {
            this.waiting.set(id, (msg) => (msg.error !== undefined ? reject(new Error(`${method}: ${msg.error.message}`)) : resolve(msg.result)));
            this.ws.send(JSON.stringify(frame));
            setTimeout(() => {
                if (this.waiting.has(id) === true) {
                    this.waiting.delete(id);
                    reject(new Error(`${method}: no answer within 20s`));
                }
            }, 20000);
        });
    }

    async evaluate(expression, session = true) {
        const result = await this.send('Runtime.evaluate', {
            expression: `JSON.stringify((function(){${expression}})())`, returnByValue: true,
        }, session);
        if (result.exceptionDetails !== undefined) {
            throw new Error(`page threw: ${result.exceptionDetails.text} ${result.exceptionDetails.exception?.description ?? ''}`);
        }
        return JSON.parse(result.result.value ?? 'null');
    }

    /** Open a page and wait until `ready` (an expression) is true, at most 8s. A poll, never a sleep (#735). */
    async open(url, ready) {
        this.errors = [];
        this.warnings = [];
        await this.send('Page.navigate', { url });
        for (let i = 0; i < 80; i += 1) {
            const ok = await this.evaluate(`return document.readyState === 'complete' && (${ready}) ? 1 : 0;`).catch(() => 0);
            if (ok === 1) { return true; }
            await new Promise((r) => setTimeout(r, 100));
        }
        return false;
    }
}

/** What one copy drew: its host's facts and its shadow root's text and elements. */
const READ = `
    var hosts = document.querySelectorAll('[data-webms-door]');
    var out = [];
    for (var i = 0; i < hosts.length; i++) {
        var h = hosts[i], r = h.shadowRoot, box = r ? r.querySelector('.door') : null;
        var links = [], pics = [], tags = {};
        if (box) {
            box.querySelectorAll('a').forEach(function (a) { links.push({ href: a.getAttribute('href'), target: a.target, rel: a.rel, ref: a.referrerPolicy, text: a.textContent }); });
            box.querySelectorAll('img').forEach(function (m) { pics.push({ src: m.getAttribute('src'), ref: m.referrerPolicy }); });
            box.querySelectorAll('*').forEach(function (e) { tags[e.tagName.toLowerCase()] = true; });
        }
        out.push({ show: h.getAttribute('data-webms-door'), parent: h.parentNode && h.parentNode.id ? h.parentNode.id : (h.parentNode ? h.parentNode.tagName.toLowerCase() : null),
            style: h.getAttribute('style'), lightChildren: h.childNodes.length, shadow: !!r,
            titles: box ? Array.prototype.map.call(box.querySelectorAll('.door__title'), function (t) { return t.textContent; }) : [],
            whens: box ? Array.prototype.map.call(box.querySelectorAll('.door__when'), function (t) { return t.textContent; }) : [],
            text: box ? box.textContent : null, links: links, pics: pics, tags: Object.keys(tags).sort(),
            empty: box ? box.children.length === 0 : null });
    }
    return out;
`;
const READY = `document.querySelectorAll('[data-webms-door]').length > 0 && Array.prototype.every.call(document.querySelectorAll('[data-webms-door]'), function (h) { var b = h.shadowRoot && h.shadowRoot.querySelector('.door'); return b && b.children.length > 0; })`;

async function main() {
    if (typeof WebSocket === 'undefined') {
        console.error(`REFUSED: this node (${process.version}) has no global WebSocket, so the browser cannot be driven.`);
        process.exit(2);
    }
    const browserPath = BROWSERS.find((p) => existsSync(p) === true);
    if (browserPath === undefined) {
        console.error('REFUSED: no Chromium-family browser found. Set CHROME_PATH to one. This test drives a REAL browser;');
        console.error('it refuses rather than skipping, because a skipped test reads exactly like a passing one.');
        process.exit(2);
    }
    const realScript = await readFile(SCRIPT_FILE, 'utf8');
    if (realScript.length < 1000) {
        console.error(`REFUSED: ${SCRIPT_FILE} is missing or nearly empty.`);
        process.exit(2);
    }
    console.log(`Browser: ${browserPath}`);

    const work = await mkdtemp(path.join(tmpdir(), 'door-script-'));

    /* ── #493 P4c: the framed page's half, made by PHP from the real code ── */
    const php = process.env.PHP_BINARY || 'php';
    const framePhp = path.join(work, 'frame-fixture.php');
    await writeFile(framePhp, FRAME_PHP);
    const certRun = spawnSync(php, [framePhp, path.join(ROOT, 'web', '_core'), 'cert'], { encoding: 'utf8' });
    if (certRun.status !== 0) {
        console.error(`REFUSED: PHP (${php}) could not make the certificate section F needs: ${(certRun.stderr || certRun.error?.message || '').trim()}`);
        await rm(work, { recursive: true, force: true });
        process.exit(2);
    }
    const tls = JSON.parse(certRun.stdout);
    const frameServed = { page: '', headers: { listed: [], none: [] } };
    let framePort = 0;
    const frameRequests = [];
    const httpsServer = createHttpsServer({ cert: tls.cert, key: tls.key }, (request, answer) => {
        const url = new URL(request.url, 'https://x');
        const host = String(request.headers.host ?? '').split(':')[0];
        if (host === 'portal-selftest.test' && url.pathname === `/w/${FRAME_NAME}/page.html`) {
            const lines = frameServed.headers[url.searchParams.get('state') ?? ''];
            if (lines === undefined) { answer.writeHead(404).end('not found'); return; }
            frameRequests.push({ referer: request.headers.referer ?? null });
            const headers = {};
            for (const line of lines) {
                const at = line.indexOf(':');
                headers[line.slice(0, at)] = line.slice(at + 1).trim();
            }
            answer.writeHead(200, headers).end(frameServed.page);
            return;
        }
        if ((host === 'listed-selftest.test' || host === 'unlisted-selftest.test') && url.pathname === '/site/frame.html') {
            const state = url.searchParams.get('state') ?? 'listed';
            // The frame exactly as the card prints it, sandbox included — but
            // FX4 asks for it WITHOUT the sandbox attribute, to show that the
            // "nothing ran" check can fail once every wall is down.
            const sandbox = url.searchParams.get('bare') === '1' ? '' : ' sandbox="allow-popups allow-popups-to-escape-sandbox"';
            answer.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(`<!doctype html><html lang="en"><head><meta charset="utf-8"><title>a website</title></head>
<body><p>A website framing the portal's page.</p>
<iframe id="door" src="https://portal-selftest.test:${framePort}/w/${FRAME_NAME}/page.html?state=${state}" title="Our events" width="100%" height="600" style="border:0" loading="lazy" referrerpolicy="no-referrer"${sandbox}></iframe>
</body></html>`);
            return;
        }
        answer.writeHead(404).end('not found');
    });
    await new Promise((resolve) => httpsServer.listen(0, '127.0.0.1', resolve));
    framePort = httpsServer.address().port;
    const frameRun = spawnSync(php, [framePhp, path.join(ROOT, 'web', '_core'), 'render', `https://listed-selftest.test:${framePort}`,
        JSON.stringify(FRAME_FIXTURE)], { encoding: 'utf8' });
    let frameReal = null;
    try { frameReal = JSON.parse(frameRun.stdout); } catch { frameReal = null; }
    if (frameRun.status !== 0 || frameReal === null || typeof frameReal.page !== 'string' || frameReal.page.length < 500) {
        console.error(`REFUSED: PHP could not draw the page from web/_core: ${(frameRun.stderr || frameRun.stdout || '').trim().slice(0, 400)}`);
        httpsServer.close();
        await rm(work, { recursive: true, force: true });
        process.exit(2);
    }
    const frameServeReal = () => {
        frameServed.page = frameReal.page;
        frameServed.headers = { listed: frameReal.listed.slice(), none: frameReal.none.slice() };
    };
    frameServeReal();
    const profile = path.join(work, 'profile');
    let served = realScript;          // what /w/*/door.js answers (a plant swaps it)
    const seen = [];                  // every feed request the "portal" received
    let port = 0;

    const server = createServer((request, answer) => {
        const url = new URL(request.url, 'http://x');
        const host = String(request.headers.host ?? '');
        const onPortal = host.startsWith('localhost:');
        if (onPortal === false && url.pathname === '/site/site.css') {
            answer.writeHead(200, { 'Content-Type': 'text/css' }).end(SITE_CSS);
            return;
        }
        if (onPortal === false && url.pathname.startsWith('/site/')) {
            const pages = {
                '/site/events.html': `<script src="PORTAL/w/EVENTSAAAAAAAAAAAAAAAAAAA/door.js" data-show="events" data-lang="en-GB" defer></script>
<div id="spot"></div>
<script src="PORTAL/w/POSTERSBBBBBBBBBBBBBBBBBB/door.js" data-show="posters" data-target="#spot" data-lang="en-GB" defer></script>`,
                '/site/next.html': `<script src="PORTAL/w/NEXTCCCCCCCCCCCCCCCCCCCCC/door.js" data-show="next" defer></script>
<script src="PORTAL/w/NOWDDDDDDDDDDDDDDDDDDDDDD/door.js" data-show="next" defer></script>`,
                '/site/noend.html': `<script src="PORTAL/w/NOENDFFFFFFFFFFFFFFFFFFFF/door.js" data-show="next" defer></script>`,
                '/site/off.html': `<script src="PORTAL/w/OFFFFFFFFFFFFFFFFFFFFFFFF/door.js" data-show="events" defer></script>
<script src="PORTAL/w/EMPTYEEEEEEEEEEEEEEEEEEEE/door.js" data-show="events" defer></script>`,
            };
            const body = pages[url.pathname];
            if (body === undefined) { answer.writeHead(404).end(); return; }
            const page = sitePage(port, body);
            answer.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': page.csp }).end(page.html);
            return;
        }
        const m = /^\/w\/([A-Z]{25})\/(door\.js|events\.json|posters\.json)$/.exec(url.pathname);
        const door = { 'Access-Control-Allow-Origin': '*', 'Cross-Origin-Resource-Policy': 'cross-origin', 'X-Content-Type-Options': 'nosniff', 'Cache-Control': 'no-store' };
        if (onPortal === true && m !== null) {
            if (m[2] === 'door.js') {
                answer.writeHead(200, { ...door, 'Content-Type': 'text/javascript; charset=utf-8' }).end(served);
                return;
            }
            seen.push({ name: m[1], what: m[2], referer: request.headers.referer ?? null, limit: url.searchParams.get('limit') });
            const feed = feeds()[m[1]];
            if (feed === undefined) {
                answer.writeHead(404, { ...door, 'Content-Type': 'text/plain; charset=utf-8' }).end('Not found.\n');
                return;
            }
            answer.writeHead(200, { ...door, 'Content-Type': 'application/json; charset=utf-8' }).end(JSON.stringify(feed));
            return;
        }
        answer.writeHead(404).end('not found');
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    port = server.address().port;

    const edge = spawn(browserPath, [
        '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--no-first-run',
        '--no-default-browser-check', '--disable-gpu', '--host-resolver-rules=MAP localhost 127.0.0.1,MAP portal-selftest.test 127.0.0.1,MAP listed-selftest.test 127.0.0.1,MAP unlisted-selftest.test 127.0.0.1',
        // #493 P4c: section F's three made-up https names share one
        // certificate the test makes for itself, which no browser trusts.
        '--ignore-certificate-errors',
    ], { stdio: 'ignore', env: { ...process.env, TZ: 'Pacific/Kiritimati' } });
    const stop = async () => {
        const ended = new Promise((resolve) => { edge.once('exit', resolve); setTimeout(resolve, 5000); });
        try { edge.kill('SIGTERM'); } catch { /* gone */ }
        await ended;
        try { server.close(); } catch { /* closed */ }
        try { httpsServer.close(); } catch { /* closed */ }
        for (let i = 0; i < 20; i += 1) {
            try { await rm(work, { recursive: true, force: true }); return; } catch { await new Promise((r) => setTimeout(r, 250)); }
        }
        console.log(`NOTE: could not remove ${work} — please delete it by hand.`);
    };

    try {
        const portFile = path.join(profile, 'DevToolsActivePort');
        let wsUrl = null;
        for (let i = 0; i < 200 && wsUrl === null; i += 1) {
            try {
                const chosen = (await readFile(portFile, 'utf8')).split('\n')[0].trim();
                wsUrl = (await (await fetch(`http://127.0.0.1:${chosen}/json/version`)).json()).webSocketDebuggerUrl;
            } catch { await new Promise((r) => setTimeout(r, 100)); }
        }
        if (wsUrl === null) { throw new Error(`the browser never opened its debugging port (${browserPath})`); }
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

        const site = `http://127.0.0.1:${port}/site`;
        /* ⚠️ NO COOKIE CHECK, ON PURPOSE. A first draft had the "portal" set a
              cookie and R01 require it absent from the feed request. Between
              127.0.0.1 and localhost the browser's own SameSite rules would
              withhold it whatever the script asked for, so that half could
              not fail — a clause that cannot fail is not a check. What really
              keeps credentials out is `credentials: 'omit'`, and X06 proves
              the script depends on it: asked WITH credentials, the door's
              `Access-Control-Allow-Origin: *` is refused and nothing draws. */

        /**
         * Every page, read once. Answers a flat object of named facts, so the
         * base checks and the plants compare the SAME facts.
         */
        const scenario = async () => {
            const f = {};
            seen.length = 0;
            f.eventsReady = await browser.open(`${site}/events.html`, READY);
            const ev = await browser.evaluate(READ);
            const evPage = await browser.evaluate(`
                var leak = getComputedStyle(document.getElementById('leak'));
                var hosts = document.querySelectorAll('[data-webms-door]');
                var inner = hosts[0] && hosts[0].shadowRoot ? hosts[0].shadowRoot.querySelector('.door__title') : null;
                var added = Object.keys(window).filter(function (k) { return window.__before.keys.indexOf(k) === -1; });
                return { violations: window.__violations, pwned: window.__pwned === undefined ? null : window.__pwned,
                    headNow: document.head.children.length, headBefore: window.__before.head,
                    pageSheets: document.styleSheets.length, pageAdopted: (document.adoptedStyleSheets || []).length,
                    styleEls: document.querySelectorAll('style').length, leakWeight: leak.fontWeight,
                    innerColour: inner ? getComputedStyle(inner).color : null, innerWeight: inner ? getComputedStyle(inner).fontWeight : null,
                    newGlobals: added.filter(function (k) { return k !== '__violations' && k !== '__before'; }),
                    localHourOf1030NY: new Date('2030-06-01T10:30:00-04:00').getHours() };`);
            f.events = ev[0] ?? null;
            f.posters = ev[1] ?? null;
            // A field put through innerHTML no longer shows its markup as
            // characters, so its value goes missing from the text.
            f.missing = {
                events: MUST_APPEAR.events.filter((one) => (f.events?.text ?? '').includes(one) === false),
                posters: MUST_APPEAR.posters.filter((one) => (f.posters?.text ?? '').includes(one) === false),
            };
            f.copies = ev.length;
            f.evPage = evPage;
            f.evErrors = browser.errors.slice();
            f.feedRequests = seen.map((s) => ({ name: s.name.slice(0, 6), what: s.what, referer: s.referer, limit: s.limit }));

            seen.length = 0;
            f.nextReady = await browser.open(`${site}/next.html`, READY);
            const nx = await browser.evaluate(READ);
            const tick = async () => browser.evaluate(`var h = document.querySelectorAll('[data-webms-door]')[0];
                var n = h && h.shadowRoot ? h.shadowRoot.querySelectorAll('.door__number') : [];
                return Array.prototype.map.call(n, function (x) { return x.textContent; });`);
            const t1 = await tick();
            await new Promise((r) => setTimeout(r, 1300));
            const t2 = await tick();
            const clocks = await browser.evaluate(`return Array.prototype.map.call(document.querySelectorAll('[data-webms-door]'), function (h) {
                var c = h.shadowRoot.querySelector('.door__clock'); return c ? (c.hidden ? 'hidden' : c.getAttribute('role')) : null; });`);
            f.next = nx.map((one) => ({ titles: one.titles, text: one.text }));
            f.missing.next = MUST_APPEAR.next.filter((one) => (nx[0]?.text ?? '').includes(one) === false);
            f.nextTick = { first: t1, second: t2 };
            f.nextClocks = clocks;
            f.nextLimit = seen.map((s) => s.limit);
            f.nextViolations = await browser.evaluate('return window.__violations;');
            f.nextErrors = browser.errors.slice();

            // #882: the countdown follows the door's own "still listed" rule.
            seen.length = 0;
            f.noEndReady = await browser.open(`${site}/noend.html`, READY);
            f.noEnd = (await browser.evaluate(READ)).map((one) => ({ titles: one.titles, text: one.text }));

            seen.length = 0;
            // The door-off copy draws nothing, so "ready" is: the empty one drew AND both asked for their feed.
            f.offReady = await browser.open(`${site}/off.html`,
                `(function () { var h = document.querySelectorAll('[data-webms-door]'); var b = h[1] && h[1].shadowRoot && h[1].shadowRoot.querySelector('.door'); return h.length === 2 && b && b.children.length > 0; })()`);
            await new Promise((r) => setTimeout(r, 400));
            const off = await browser.evaluate(READ);
            f.off = off.map((one) => ({ empty: one.empty, text: one.text }));
            f.offAsked = seen.map((s) => s.name.slice(0, 5)).sort();
            f.offErrors = browser.errors.slice();
            f.offWarned = browser.warnings.some((w) => w.startsWith('[webms-door] could not read'));
            return f;
        };

        const expectNY = 'New York';
        const base = await scenario();

        console.log('E. Events: text only, links only to https:, times on the event\'s own clock');
        check('E00 the premise: the browser\'s own clock is Kiritimati\'s (UTC+14), so 10:30 in New York would read as some other hour if converted',
            base.evPage.localHourOf1030NY !== 10, true);
        check('E01 the page drew, two copies, each in its own shadow root on an element of its own', [base.eventsReady, base.copies, base.events?.shadow, base.posters?.shadow], [true, 2, true, true]);
        check('E02 a title full of markup appears as those very characters — nothing turned into HTML', base.events?.titles, [EVIL_TITLE, 'Https venue', 'Plain venue', 'Zone markup', 'Missing hour']);
        check('E03 nothing in the feed ran: no onerror, no <script>, no javascript: link (window.__pwned never set)', base.evPage.pwned, null);
        check('E04 the description and place also appear as text, and the ONLY elements drawn are the script\'s own (no img, script, b or svg from the feed)',
            [base.events?.text?.includes(EVIL_ABOUT), base.events?.text?.includes(EVIL_PLACE_NAME), base.events?.tags], [true, true, ['a', 'li', 'p', 'ul']]);
        check('E05 one link, to the https: venue only (the javascript: and http: ones dropped), opened in a new tab with noopener, noreferrer and no referrer',
            base.events?.links, [{ href: 'https://venue.example.invalid/', target: '_blank', rel: 'noopener noreferrer nofollow', ref: 'no-referrer', text: 'Venue details' }]);
        check('E06 the New York event\'s time is 10:30 on New York\'s own clock, with its zone named because it is not the organisation\'s',
            [base.events?.whens?.[0]?.includes('10:30'), base.events?.whens?.[0]?.includes('2030'), base.events?.whens?.[0]?.endsWith(`(${expectNY} time)`)], [true, true, true]);
        check('E07 an event on the ORGANISATION\'s own clock carries no zone label at all (round 1 of P4b\'s check: only the labelled case was looked at)',
            [base.events?.whens?.[1]?.includes('('), base.events?.whens?.[2]?.includes('('), base.events?.whens?.[4]?.includes('(')], [false, false, false]);
        // en-GB writes the hour without a leading zero ("1:30"), so the check
        // is on the hour and minute as written, and on 2:30 being nowhere.
        check('E08 on the morning the clocks go forward, an event stored at 01:30 is SHOWN at 1:30 (startsOnClock, as the portal shows it), not 2:30 (its instant) (#777)',
            [/\b0?1:30$/.test(base.events?.whens?.[4] ?? ''), /2:30/.test(base.events?.whens?.[4] ?? '')], [true, false]);
        check('E09 EVERY hostile value appears character for character in the text drawn — every field the script draws (title, description, place, address, zone label; poster title, kicker, place, time; the countdown\'s title)',
            base.missing, { events: [], posters: [], next: [] });

        console.log('P. Posters, and a second copy on the same page');
        check('P01 the posters copy drew into the place its data-target named; the events copy just before its own script tag', [base.posters?.parent, base.events?.parent], ['spot', 'body']);
        check('P02 the poster title with markup is text, and its javascript: link is dropped; the https: one is kept',
            [base.posters?.titles, base.posters?.links?.map((l) => l.href), base.posters?.tags], [[EVIL_POSTER, 'Second poster'], ['https://more.example.invalid/'], ['a', 'img', 'li', 'p', 'ul']]);
        check('P03 a picture only from https: (the http: one dropped), sent with no referrer', base.posters?.pics, [{ src: 'https://pictures.example.invalid/a.png', ref: 'no-referrer' }]);
        check('P04 a weekly poster says "Every Tuesday, " and its time, which arrived as markup and is shown as those characters', base.posters?.whens?.[0], `Every Tuesday, ${EVIL_TIME}`);
        check('P05 the two copies did not cross: the events copy holds no poster, the posters copy no event',
            [base.events?.text?.includes('Second poster'), base.posters?.text?.includes('Https venue')], [false, false]);

        console.log('N. "next" — the countdown (D-084)');
        check('N01 the soonest by REAL instant (New York, in two hours) though the feed lists Auckland first; the other copy says an event is under way',
            [base.nextReady, base.next.map((n) => n.titles), base.next[0]?.text?.startsWith('Next'), base.next[1]?.text?.startsWith('Happening now')],
            [true, [[EVIL_NEXT], ['Under way']], true, true]);
        check('N02 every box of the countdown: 2 days, 03 hours, 25 minutes, and the seconds box moving (round 1 of P4b\'s check: only days and hours were looked at, on an event two hours away)',
            [base.nextTick.first[0], base.nextTick.first[1], base.nextTick.first[2], /^[0-5][0-9]$/.test(base.nextTick.first[3] ?? ''),
                base.nextTick.first[3] !== base.nextTick.second[3]], ['2', '03', '25', true, true]);
        check('N03 the clock is a timer for assistive technology, and hidden for the event under way', base.nextClocks, ['timer', 'hidden']);
        check('N04 "next" asks for 50 events, so it can choose by instant rather than by the feed\'s order', base.nextLimit, ['50', '50']);
        check('N05 (#882, D-129) an event with NO end time that the door still lists (its listedUntil is three hours away) is "Happening now", not skipped for the next one; one whose listedUntil has passed is not shown',
            [base.noEndReady, base.noEnd.map((n) => n.titles), base.noEnd[0]?.text?.startsWith('Happening now')],
            [true, [['Still on, no end time']], true]);

        console.log('O. The door off, and an empty feed');
        check('O01 a door that refuses its feed draws NOTHING; an empty feed says "No upcoming events."', [base.offReady, base.off], [true, [{ empty: true, text: '' }, { empty: false, text: 'No upcoming events.' }]]);
        check('O02 both asked for their feed (so "nothing drawn" is the script choosing silence, not a script that never ran)', base.offAsked, ['EMPTY', 'OFFFF']);
        check('O03 the refusal is one warning for whoever sets it up, never an error', [base.offWarned, base.offErrors], [true, []]);

        console.log('I. Isolation: the website\'s page is left alone');
        check('I01 exactly one element per copy, with no style attribute and nothing in the light DOM', [base.events?.style, base.events?.lightChildren, base.posters?.style, base.posters?.lightChildren], [null, 0, null, 0]);
        check('I02 nothing added to the page\'s <head>, no <style> anywhere in the page, no stylesheet added to the page', [base.evPage.headNow === base.evPage.headBefore, base.evPage.styleEls, base.evPage.pageSheets, base.evPage.pageAdopted], [true, 0, 1, 0]);
        check('I03 the website\'s own styles do not reach in (the script\'s title is not the page\'s red), and the script\'s do not leak out (the page\'s own .door__title is not bold)',
            [base.evPage.innerColour !== 'rgb(255, 0, 0)', base.evPage.innerWeight, base.evPage.leakWeight], [true, '700', '400']);
        check('I04 no new global variable', base.evPage.newGlobals, []);
        check('I05 not one Content-Security-Policy violation under a policy with no inline style and no eval, on any page', [base.evPage.violations, base.nextViolations], [[], []]);
        check('I06 no uncaught error or console error on any page', [base.evErrors, base.nextErrors], [[], []]);

        console.log('R. What the portal received');
        check('R01 the feeds were asked for with NO Referer (the website\'s address is not handed to the portal), and with data-limit\'s default of 10',
            base.feedRequests.slice().sort((a, b) => a.name.localeCompare(b.name)),
            [{ name: 'EVENTS', what: 'events.json', referer: null, limit: '10' }, { name: 'POSTER', what: 'posters.json', referer: null, limit: '10' }]);

        console.log('X. The plants: each rule broken in a copy of the script served in its place');
        const plant = async (label, from, to, notices) => {
            if (realScript.split(from).length !== 2) {
                check(`${label} — PLANT NOT APPLIED (the text was not found exactly once)`, false, true);
                return;
            }
            served = realScript.replace(from, to);
            let r;
            try { r = await scenario(); } finally { served = realScript; }
            check(label, notices(r), true);
        };
        await plant('X01 textContent → innerHTML: E03 sees the feed\'s markup run (or E02 sees it parsed)', 'node.textContent = words;', 'node.innerHTML = words;',
            (r) => r.evPage.pwned !== null || JSON.stringify(r.events?.titles) !== JSON.stringify(base.events?.titles));
        await plant('X02 a <style> put into the page\'s <head>: I02 and I05 notice',
            "    applyStyles(shadow);\n", "    applyStyles(shadow);\n    var leak = document.createElement('style'); leak.textContent = '.door__title{font-weight:900}'; document.head.appendChild(leak);\n",
            (r) => r.evPage.styleEls !== 0 && r.evPage.violations.length > 0);
        await plant('X03 the constructed stylesheet taken away (a <style> in the shadow root instead): I05 sees the website\'s policy refuse it',
            "if (typeof CSSStyleSheet === 'function' && 'adoptedStyleSheets' in root) {", 'if (false) {', (r) => r.evPage.violations.length > 0);
        await plant('X04 an eval() added: I05 or I06 notices', "    var LABEL = '[webms-door]';", "    var LABEL = eval(\"'[webms-door]'\");",
            (r) => r.evPage.violations.length > 0 || r.evErrors.length > 0);
        await plant('X05 the https-only rule taken away: E05 sees a javascript: or http: link drawn', "return u.protocol === 'https:' ? u.href : null;", 'return u.href;',
            (r) => JSON.stringify(r.events?.links) !== JSON.stringify(base.events?.links));
        await plant('X06 the feed asked for WITH credentials: the door\'s CORS * refuses it, so nothing is drawn (E01 times out)', "{ credentials: 'omit', mode: 'cors'", "{ credentials: 'include', mode: 'cors'",
            (r) => r.eventsReady === false);
        await plant('X07 the feed asked for with the browser\'s usual referrer: R01 sees a Referer', ", referrerPolicy: 'no-referrer' })", ' })',
            (r) => r.feedRequests.some((q) => q.referer !== null));
        await plant('X08 "next" choosing the FIRST in the feed rather than the soonest instant: N01 notices', 'if (best === null || start < best.start) {', 'if (best === null) {',
            (r) => JSON.stringify(r.next.map((n) => n.titles)) !== JSON.stringify(base.next.map((n) => n.titles)));
        await plant('X08b (#882) the countdown ignoring the door\'s listedUntil (the script before #882): N05 notices', "if (typeof ev.listedUntil === 'string') {", 'if (false) {',
            (r) => JSON.stringify(r.noEnd.map((n) => n.titles)) !== JSON.stringify(base.noEnd.map((n) => n.titles)));
        await plant('X09 a global variable leaked (the wrapper taken away from one name): I04 notices', "(function () {\n    'use strict';", "window.webmsDoor = true;\n(function () {\n    'use strict';",
            (r) => r.evPage.newGlobals.length > 0);
        await plant('X10 the event\'s time taken from the instant rather than its own clock reading: E06 notices', 'var reading = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]));',
            'var reading = new Date(Date.parse(stamp));', (r) => r.events?.text?.includes('10:30') === false);
        await plant('X11 the host added with an inline style: I01 notices', "    host.setAttribute('data-webms-door', show);", "    host.setAttribute('data-webms-door', show);\n    host.setAttribute('style', 'display:block');",
            (r) => r.events?.style !== null);
        await plant('X12 the door-off case drawing an apology instead of silence: O01 notices', "                clear();\n                warn('could not read '",
            "                clear();\n                box.appendChild(el('p', '', 'Sorry'));\n                warn('could not read '", (r) => r.off[0]?.empty !== true);
        // Round 1 of P4b's check: each drawn field broken ON ITS OWN, because
        // the shared el() plant (X01) cannot tell one field from another.
        const asHtml = (cls, value) => `(function () { var n = document.createElement('p'); n.className = '${cls}'; n.innerHTML = ${value}; item.appendChild(n); })();`;
        await plant('X14 innerHTML on the poster\'s KICKER alone: E09 notices', "item.appendChild(el('p', 'door__kicker', text(p.kicker)));",
            asHtml('door__kicker', 'text(p.kicker)'), (r) => r.missing.posters.includes(EVIL_KICKER));
        await plant('X15 innerHTML on the poster\'s LOCATION alone: E09 notices', "item.appendChild(el('p', 'door__where', text(p.location)));",
            asHtml('door__where', 'text(p.location)'), (r) => r.missing.posters.includes(EVIL_POSTER_PLACE));
        await plant('X16 innerHTML on the poster\'s day-and-time line alone: E09 notices',
            "var when = whenOfPoster(p);\n            if (when !== '') {\n                item.appendChild(el('p', 'door__when', when));",
            "var when = whenOfPoster(p);\n            if (when !== '') {\n                " + asHtml('door__when', 'when'), (r) => r.missing.posters.includes(EVIL_TIME));
        await plant('X17 innerHTML on the event\'s place-and-ADDRESS line alone: E09 notices', "item.appendChild(el('p', 'door__where', where));",
            asHtml('door__where', 'where'), (r) => r.missing.events.includes(EVIL_ADDRESS));
        await plant('X18 innerHTML on the event\'s DESCRIPTION alone: E09 notices', "item.appendChild(el('p', 'door__about', text(ev.description)));",
            asHtml('door__about', 'text(ev.description)'), (r) => r.missing.events.includes(EVIL_ABOUT));
        await plant('X19 innerHTML on the event\'s day-and-time line (with its ZONE label) alone: E09 notices',
            "var when = whenOfEvent(ev);\n            if (when !== '') {\n                item.appendChild(el('p', 'door__when', when));",
            "var when = whenOfEvent(ev);\n            if (when !== '') {\n                " + asHtml('door__when', 'when'), (r) => r.missing.events.includes(`(${EVIL_ZONE_PLACE} time)`));
        await plant('X20 a zone label on EVERY event, the organisation\'s own clock included: E07 notices', "if (zone !== '' && org !== '' && zone !== org) {", "if (zone !== '') {",
            (r) => r.events?.whens?.[1]?.includes('(') === true);
        await plant('X21 the time SHOWN taken from the instant (startsAt) rather than the stored reading: E08 sees 02:30', "var shown = typeof ev.startsOnClock === 'string' ? ev.startsOnClock : ev.startsAt;",
            'var shown = ev.startsAt;', (r) => /2:30/.test(r.events?.whens?.[4] ?? ''));
        // Round 1 of P4b's check: each box of the countdown, one at a time.
        await plant('X22 the DAYS box counting hours: N02 notices', 'parts.days.textContent = String(Math.floor(left / 86400));',
            'parts.days.textContent = String(Math.floor(left / 3600));', (r) => r.nextTick.first[0] !== '2');
        await plant('X23 the HOURS box not wrapping at a day: N02 notices', 'parts.hours.textContent = two(Math.floor((left % 86400) / 3600));',
            'parts.hours.textContent = two(Math.floor(left / 3600));', (r) => r.nextTick.first[1] !== '03');
        await plant('X24 the MINUTES box not wrapping at an hour (round 1\'s own plant): N02 notices', 'parts.minutes.textContent = two(Math.floor((left % 3600) / 60));',
            'parts.minutes.textContent = two(Math.floor(left / 60));', (r) => r.nextTick.first[2] !== '25');
        await plant('X25 the SECONDS box frozen: N02 notices', 'parts.seconds.textContent = two(left % 60);', 'parts.seconds.textContent = two(0);',
            (r) => r.nextTick.first[3] === r.nextTick.second[3]);
        // The control: an edit that changes nothing a visitor can see must stay GREEN,
        // or "red" above would mean only "the file changed".
        served = realScript.replace('// 🛟 Too old a browser', '// 🛟 Too old a browser (a harmless edit)');
        const control = await scenario();
        served = realScript;
        /* The facts that cannot move between two runs. Times WRITTEN OUT can:
           the made-up feeds are built from "now" at each request, so an event
           a day ahead and a countdown's digits read differently a few seconds
           later. So the drawn TEXT is left out of the comparison, and
           everything else — titles, links, pictures, elements, isolation,
           violations, errors, what was asked for — is compared whole. */
        const steady = (f) => {
            const out = { ...f, nextTick: null, feedRequests: null };
            for (const k of ['events', 'posters']) {
                out[k] = f[k] === null ? null : { ...f[k], text: null, whens: null };
            }
            out.next = f.next.map((n) => n.titles);
            // #882: the no-end countdown's text holds a moving clock too.
            out.noEnd = f.noEnd.map((n) => n.titles);
            return out;
        };
        const sb = steady(base);
        const sc = steady(control);
        const differs = Object.keys(sb).filter((k) => JSON.stringify(sc[k]) !== JSON.stringify(sb[k]));
        check('X13 the CONTROL: a comment changed, and every fact above is exactly as before (the list of facts that differ is empty)', differs, []);

        /* ── F. #493 P4c: the page a website shows in a frame ─────────────
           Whether a browser REFUSES a frame is behaviour (D-065), so it is
           asked of the browser: a refused frame becomes Chromium's own error
           page (chrome-error://chromewebdata/) — a POSITIVE sign of refusal,
           waited for rather than assumed from an empty frame. */
        console.log('F. The page a website frames (#493 P4c; D-082, D-094)');
        await browser.send('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: false, flatten: true });
        const portalPage = (state) => `https://portal-selftest.test:${framePort}/w/${FRAME_NAME}/page.html?state=${state}`;
        /** Open a website framing the page; read what the frame holds, from INSIDE it. */
        const framed = async (site, state, bare = false) => {
            browser.frames = [];
            await browser.send('Page.navigate', { url: `https://${site}-selftest.test:${framePort}/site/frame.html?state=${state}${bare === true ? '&bare=1' : ''}` });
            for (let i = 0; i < 100; i += 1) {
                for (const f of browser.frames.filter((one) => one.type === 'iframe')) {
                    let r = null;
                    try { r = await browser.evaluate(FRAME_PROBE, f.session); } catch { r = null; }
                    if (r === null) { continue; }
                    if (r.url.startsWith('chrome-error:') === true) {
                        return { refused: true };
                    }
                    if (r.url === portalPage(state) && r.ready === 'complete' && r.text.trim() !== '') {
                        // An error event may follow load by a moment: look again.
                        await new Promise((done) => setTimeout(done, 400));
                        const again = await browser.evaluate(FRAME_PROBE, f.session).catch(() => r);
                        return { refused: false, ...again, missing: FRAME_MUST_APPEAR.filter((one) => again.text.includes(one) === false) };
                    }
                }
                await new Promise((done) => setTimeout(done, 100));
            }
            return { refused: null };
        };
        const facts = (r) => ({ refused: r.refused, missing: r.missing, pwned: r.pwned, scripts: r.scripts, weight: r.weight, links: r.links });
        const wantShown = {
            refused: false, missing: [], pwned: null, scripts: 0, weight: '700',
            links: [{ href: 'https://venue.example.invalid/', target: '_blank', rel: 'noopener noreferrer nofollow' },
                { href: 'https://more.example.invalid/', target: '_blank', rel: 'noopener noreferrer nofollow' }],
        };
        const directOk = await browser.open(portalPage('listed'), "document.querySelector('.door__title') !== null");
        const direct = await browser.evaluate(FRAME_PROBE);
        check('F01 the page opened on its own: every hostile value appears as those very characters, no element made of them, nothing ran, and the title is bold — its ONE stylesheet allowed by the hash in its content rule',
            [directOk, facts({ ...direct, refused: false, missing: FRAME_MUST_APPEAR.filter((one) => direct.text.includes(one) === false) })],
            [true, wantShown]);
        const fListed = await framed('listed', 'listed');
        check('F02 a website the organisation LISTS shows it in a frame — the same page, the same facts — under the real header (frame-ancestors from frameAncestorsValue())',
            [frameReal.listed.some((l) => l.includes(`frame-ancestors https://listed-selftest.test:${framePort};`)), facts(fListed)], [true, wantShown]);
        const fUnlisted = await framed('unlisted', 'listed');
        check('F03 a website NOT listed, framing the very same page with the very same headers, is refused by the browser (D-082)', fUnlisted.refused, true);
        const fNone = await framed('listed', 'none');
        check('F04 with nothing listed (or framing forbidden for the installation, which gives the same header: frame-ancestors \'none\' and X-Frame-Options DENY), even that website is refused',
            [frameReal.none.includes('X-Frame-Options: DENY'), fNone.refused], [true, true]);
        check('F05 the page was asked for with NO Referer from the website framing it (the snippet\'s referrerpolicy), every time', frameRequests.every((q) => q.referer === null) && frameRequests.length >= 3, true);

        // The plants, each in what is SERVED, the real page and headers put back after.
        const framePlant = async (label, change, run, notices) => {
            change();
            let r;
            try { r = await run(); } finally { frameServeReal(); }
            check(label, notices(r), true);
        };
        await framePlant('FX1 frame-ancestors widened to "*": F03 sees the website not listed show the page',
            () => { frameServed.headers.listed = frameServed.headers.listed.map((l) => l.replace(/frame-ancestors [^;]+;/, 'frame-ancestors *;')); },
            () => framed('unlisted', 'listed'), (r) => r.refused === false);
        await framePlant('FX2 the stylesheet\'s hash altered: F02 sees the browser refuse the stylesheet (the title no longer bold)',
            () => { frameServed.headers.listed = frameServed.headers.listed.map((l) => l.replace(/'sha256-[^']+'/, "'sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='")); },
            () => framed('listed', 'listed'), (r) => r.refused === false && r.weight !== '700');
        const rawTitle = () => { frameServed.page = frameServed.page.replace(/<p class="door__title">[^<]*<\/p>/, `<p class="door__title">${FRAME_TITLE}</p>`); };
        await framePlant('FX3 the title written UNESCAPED: F02 sees the markup (the value no longer appears as characters) — and the content rule and the sandbox still let nothing run',
            rawTitle, () => framed('listed', 'listed'), (r) => r.refused === false && r.missing.includes(FRAME_TITLE) && r.pwned === null);
        await framePlant('FX4 unescaped, with the content rule AND the frame\'s sandbox taken away: the planted handler runs (so F02\'s "nothing ran" can fail)',
            () => { rawTitle(); frameServed.headers.listed = frameServed.headers.listed.filter((l) => l.startsWith('Content-Security-Policy:') === false); },
            () => framed('listed', 'listed', true), (r) => r.refused === false && r.pwned === 11);
        await framePlant('FX5 the CONTROL: a comment added to the page, and every fact of F02 is exactly as before',
            () => { frameServed.page = frameServed.page.replace('</main>', '</main>\n<!-- a harmless comment -->'); },
            () => framed('listed', 'listed'), (r) => JSON.stringify(facts(r)) === JSON.stringify(wantShown));
    } finally {
        await stop();
    }

    const total = passed + failed;
    console.log('');
    console.log(`${total} checks: ${passed} passed, ${failed} failed (expected ${EXPECTED_CHECKS}).`);
    process.exit(failed === 0 && total === EXPECTED_CHECKS ? 0 : 1);
}

main().catch((error) => {
    console.error(`REFUSED: ${error.message}`);
    process.exit(2);
});
