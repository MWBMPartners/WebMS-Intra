/**
 * -----------------------------------------------------------------------------
 * File:        tools/top-bar-selftest.mjs
 * Description: The browser half of tools/top-bar-selftest.php. Signed in with
 *              the cookie it is given, it loads real portal pages in a real
 *              browser at five screen widths and checks that the shared top
 *              bar fits the screen — closed, and with its Apps menu open —
 *              and that the cookie banner and the "Install app" prompt never
 *              sit on top of each other.
 * Package:     Portal\Tools
 * Author:      MWBM Partners Ltd (t/a MWservices)
 * Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 * Version:     1.0.0
 * Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/822
 * -----------------------------------------------------------------------------
 *
 * WHY THIS EXISTS (#822)
 *   Signed in as a root administrator with every app switched on, the top bar
 *   was 5,091px wide on a 1024px screen — every app was its own entry on the
 *   bar — and a long organisation name alone made a phone 826px wide. Every
 *   earlier audit missed it, because they ran signed out or as an account with
 *   few apps: the fault needs a CROWDED bar, which is the state no ordinary
 *   test world is in. This test builds that state on purpose and measures it.
 *   Only a browser can: whether a bar fits is decided by fonts, flex layout
 *   and media queries, none of which a check on the markup can see (D-065:
 *   where the property is "this RENDERS like this", drive it).
 *
 * Usage: node tools/top-bar-selftest.mjs --base=http://127.0.0.1:PORT \
 *          --cookie=NAME=VALUE --label=root --min-apps=30
 *   Normally started by tools/top-bar-selftest.php, which builds the portal,
 *   the crowded test world and the session. Exit 0 = every check passed,
 *   1 = a check failed, 2 = it could not run (never a pass).
 *
 * WHAT IT CANNOT SEE, said plainly
 *   * Whether the bar LOOKS right. It measures widths, rows and what is drawn
 *     on top of what; a human still has to look (layout-audit.mjs's
 *     --keep-screenshots is for that).
 *   * Widths it is not given. It checks 320, 390, 768, 1024, 1200 and 1440
 *     (layout-audit.mjs's five plus 1200). A fault only at, say, 1100 would pass.
 *   * The "Install app" prompt as a browser really shows it. The browser only
 *     fires `beforeinstallprompt` after its own engagement rules, so the test
 *     fires the event itself; what it proves is the layout once the prompt is
 *     up, not when a browser decides to offer it.
 *   * Text sizes other than the default, and languages other than English.
 *   * Which links the bar holds, or whether each one is the RIGHT link: it
 *     counts the Apps menu's entries only as a premise. The permission tests
 *     for each entry are unchanged code in nav.php, covered by the page tests.
 *   * A real screen reader. It checks `aria-expanded`, focus and visibility,
 *     which is what a screen reader is told — not what one then says.
 *   * Hover without JavaScript on any menu but Apps (the Admin and account
 *     menus hang from their own buttons, with no gap to cross).
 *
 * WHAT IT DOES DRIVE, beyond widths (#822 round 1 found both faults below by
 * hand, and the first version of this test passed on them):
 *   * The keyboard: focusing the Apps button must NOT show the menu (with
 *     scripts on), Enter must open it with aria-expanded="true", and Escape
 *     must hide it again with aria-expanded="false" and focus on the button.
 *     "Focus alone shows nothing" is checked on EVERY menu button on the bar
 *     (round 2: covering only Apps and Admin let the account menu open on
 *     focus with exit 0).
 *   * A resting pointer must not pop ANY menu open while Bootstrap works.
 *   * With Bootstrap BLOCKED and scripts on, the fallback must still apply:
 *     the page must not claim its menus work, and focus must open every menu
 *     (round 2: the mark was set by the head script whatever happened next,
 *     so every menu was dead).
 *   * With scripts OFF, a mouse moved straight down from the Apps button in
 *     2px steps must keep the menu open all the way to its first entry — the
 *     13-14px gap under the button used to close it, so no app was reachable.
 *     And keyboard focus must still open it there, the only way in.
 */

import { spawn } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';

// ---------------------------------------------------------------------------
// ⚙️ Arguments
// ---------------------------------------------------------------------------
function arg(name, fallback = null) {
    const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
    return hit ? hit.slice(name.length + 3) : fallback;
}
const BASE = arg('base');
const COOKIE = arg('cookie');
const LABEL = arg('label', 'account');
const MIN_APPS = Number(arg('min-apps', '30'));
// --switcher=1: the test world has several organisations, so the organisation
// switcher MUST be on the bar (a premise, checked), and the brand's own copy of
// the name may give way to it.
const SWITCHER = arg('switcher', '0') === '1';
// 1200 as well as the five layout-audit.mjs uses: it is where the person's
// own name appears on the bar, and where #822 round 1 measured the
// organisation's name squeezed to a 4px sliver beside the switcher.
const WIDTHS = [320, 390, 768, 1024, 1200, 1440];
// /calendar on purpose: its Day/Week/Month buttons are positioned elements,
// which is what was drawn ON TOP of the open menu before the bar was given a
// z-index (#822). A page without such elements could not show that fault.
const PAGES = (arg('pages', '/dashboard,/calendar')).split(',').filter(Boolean);
if (!BASE || !COOKIE) {
    console.error('REFUSED: --base and --cookie are both needed (see the header).');
    process.exit(2);
}
if (typeof WebSocket === 'undefined') {
    console.error(`REFUSED: this node (${process.version}) has no built-in WebSocket; node 22 or newer is needed.`);
    process.exit(2);
}

/* 🌐 Any Chromium-family browser, as tools/csrf-carriers-selftest.mjs does,
      so this can run on a Linux runner with Chrome as well as on a Mac with
      Edge. `CHROME_PATH` first, so a runner can say which. */
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

// ---------------------------------------------------------------------------
// ✅ Reporting
// ---------------------------------------------------------------------------
let passed = 0;
let failed = 0;
function check(name, ok, detail = '') {
    if (ok === true) {
        passed += 1;
        console.log(`  ✓ ${name}`);
    } else {
        failed += 1;
        console.log(`  ✗ ${name}${detail !== '' ? `\n      ${detail}` : ''}`);
    }
}

// ---------------------------------------------------------------------------
// 🔍 What runs INSIDE the page.
//    Every width is compared with the width ASKED FOR, never with the page's
//    own innerWidth: with `mobile: true` Chromium widens the layout viewport
//    to fit the content, so a page can never be "wider than its innerWidth".
//    That trap is written up in tools/layout-audit.mjs's header.
// ---------------------------------------------------------------------------
const CLOSED = `(() => {
  const nav = document.querySelector('nav.portal-navbar');
  if (!nav) return { noBar: true };
  // Each entry ON the bar must be one line. A squeezed bar used to break
  // "Dashboard" under its own icon, doubling the bar's height.
  // (A computed line-height of "normal" reads as NaN, and NaN compares false,
  // which would make this check impossible to fail — so fall back to 1.5 lines
  // of the font size, Bootstrap's own body line-height.)
  const twoLines = [...nav.querySelectorAll('#portalNav > .navbar-nav > .nav-item > .nav-link')]
    .filter(a => {
      const cs = getComputedStyle(a);
      const line = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.5;
      return a.getBoundingClientRect().height > 1.6 * line + parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom);
    })
    .map(a => a.textContent.trim());
  const toggler = nav.querySelector('.navbar-toggler');
  // The organisation's name in the brand: either really readable (40px is
  // about three letters and the "…"), or not drawn at all because the
  // organisation switcher's button shows it. A sliver in between — 0-4px of
  // text beside the logo — is the fault #822 round 1 found.
  const name = nav.querySelector('.portal-navbar-name');
  const sw = nav.querySelector('[aria-label="Switch organisation"]');
  const swShown = sw !== null && sw.getBoundingClientRect().width > 0 && getComputedStyle(sw).display !== 'none';
  return {
    nameWidth: name ? Math.round(name.getBoundingClientRect().width) : -1,
    nameShown: name !== null && getComputedStyle(name).display !== 'none',
    switcher: sw !== null,
    switcherShown: swShown,
    switcherNames: swShown && name !== null && sw.textContent.trim() === name.textContent.trim(),
    doc: document.documentElement.scrollWidth,
    twoLines,
    collapsed: toggler !== null && getComputedStyle(toggler).display !== 'none',
  };
})()`;

const OPEN_MENU = (asked) => `(async () => {
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const toggler = document.querySelector('nav.portal-navbar .navbar-toggler');
  if (toggler && getComputedStyle(toggler).display !== 'none') { toggler.click(); await wait(500); }
  const button = document.getElementById('portalNavApps');
  if (!button) return { noMenu: true };
  button.click();
  await wait(250);
  const menu = button.nextElementSibling;
  const box = menu.getBoundingClientRect();
  const entries = [...menu.querySelectorAll('.dropdown-item')];
  // What is drawn on top at the middle of each entry that is on the screen?
  // It must be the entry itself (or something inside it). Anything else means
  // the page is painting over the open menu.
  const covered = [];
  for (const a of entries) {
    const r = a.getBoundingClientRect();
    const x = r.left + r.width / 2, y = r.top + r.height / 2;
    if (y < 0 || y > innerHeight || x < 0 || x > ${asked}) continue;
    const top = document.elementFromPoint(x, y);
    // The cookie banner and the install prompt are MEANT to sit above
    // everything, the menu included (z-index 1080, above the bar's 1020), so
    // an entry under one of them is not a fault. They are found by their ids,
    // which every version of footer.php has used.
    if (top !== null && top.closest('#portal-cookie-banner, #portal-install-prompt') !== null) continue;
    if (top === null || (top !== a && a.contains(top) === false)) {
      covered.push(a.textContent.trim() + ' (under ' + (top ? top.tagName.toLowerCase() + (top.className && typeof top.className === 'string' ? '.' + top.className.trim().split(/\\s+/)[0] : '') : 'nothing') + ')');
    }
  }
  return {
    doc: document.documentElement.scrollWidth,
    left: Math.round(box.left), right: Math.round(box.right),
    shown: getComputedStyle(menu).display,
    expanded: button.getAttribute('aria-expanded'),
    entries: entries.length,
    covered: covered.slice(0, 4), coveredCount: covered.length,
  };
})()`;

const NOTICES = `(async () => {
  const banner = document.getElementById('portal-cookie-banner');
  if (!banner) return { noBanner: true };
  // Fire the browser's own event, as pwa-install.js expects it (see header).
  const ev = new Event('beforeinstallprompt', { cancelable: true });
  ev.prompt = () => {};
  ev.userChoice = Promise.resolve({ outcome: 'dismissed' });
  window.dispatchEvent(ev);
  await new Promise(r => setTimeout(r, 200));
  const prompt = document.getElementById('portal-install-prompt');
  const shown = el => el !== null && getComputedStyle(el).display !== 'none';
  if (!shown(prompt)) return { noPrompt: true };
  const a = banner.getBoundingClientRect(), b = prompt.getBoundingClientRect();
  const apart = b.bottom <= a.top || a.bottom <= b.top || b.right <= a.left || a.right <= b.left;
  return { apart, banner: [Math.round(a.top), Math.round(a.bottom)], prompt: [Math.round(b.top), Math.round(b.bottom)] };
})()`;

// What the Apps menu and its button say right now, and where they are.
const APPS_STATE = `(() => {
  const b = document.getElementById('portalNavApps');
  if (!b) return { noMenu: true };
  const m = b.nextElementSibling;
  const rb = b.getBoundingClientRect(), rm = m.getBoundingClientRect();
  const first = m.querySelector('.dropdown-item');
  const rf = first ? first.getBoundingClientRect() : null;
  return {
    shown: getComputedStyle(m).display !== 'none' && rm.height > 0,
    expanded: b.getAttribute('aria-expanded'),
    focusOnButton: document.activeElement === b,
    button: { x: rb.left + rb.width / 2, y: rb.top + rb.height / 2, bottom: rb.bottom },
    first: rf ? { x: rf.left + rf.width / 2, y: rf.top + rf.height / 2 } : null,
  };
})()`;
// EVERY menu button on the bar, not one or two of them (#822 round 2: the
// focus and hover checks covered Apps and Admin only, so a rule opening the
// ACCOUNT menu on focus, or Admin on hover, passed with exit 0). The i-th
// visible `[data-bs-toggle="dropdown"]`; focused first when `doFocus`.
const TOGGLE_STATE = (i, doFocus) => `(() => {
  const ts = [...document.querySelectorAll('nav.portal-navbar [data-bs-toggle="dropdown"]')]
    .filter(t => t.getClientRects().length > 0);
  const t = ts[${i}];
  if (!t) return { none: true, count: ts.length };
  if (${doFocus ? 'true' : 'false'}) t.focus();
  const m = t.parentElement.querySelector(':scope > .dropdown-menu');
  const r = t.getBoundingClientRect(), rm = m ? m.getBoundingClientRect() : { height: 0 };
  return {
    count: ts.length,
    label: (t.id || t.getAttribute('aria-label') || t.textContent.trim()).slice(0, 40),
    shown: m !== null && getComputedStyle(m).display !== 'none' && rm.height > 0,
    expanded: t.getAttribute('aria-expanded'),
    x: r.left + r.width / 2, y: r.top + r.height / 2,
  };
})()`;
const MARK = `(() => ({ mark: document.documentElement.getAttribute('data-portal-menus'),
  bootstrap: typeof window.bootstrap !== 'undefined' && typeof window.bootstrap.Dropdown === 'function' }))()`;

// ---------------------------------------------------------------------------
// 🧭 The browser
// ---------------------------------------------------------------------------
async function main() {
    let browserPath = null;
    for (const candidate of BROWSERS) {
        try { await readFile(candidate); browserPath = candidate; break; } catch { /* next */ }
    }
    if (browserPath === null) {
        console.error('REFUSED: no Chromium-family browser found. Set CHROME_PATH to one.');
        process.exit(2);
    }
    const work = await mkdtemp(path.join(os.tmpdir(), 'top-bar-selftest-'));
    const edge = spawn(browserPath, [
        '--headless=new', '--remote-debugging-port=0', `--user-data-dir=${work}`,
        '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--hide-scrollbars',
    ], { stdio: 'ignore' });

    /* ⚠️ Wait for the browser to EXIT before removing its profile: removing it
          at once raced its cache writer and threw ENOTEMPTY after every check
          had passed (tools/csrf-carriers-selftest.mjs, measured). */
    const stop = async () => {
        const ended = new Promise((resolve) => { edge.once('exit', resolve); setTimeout(resolve, 5000); });
        try { edge.kill('SIGTERM'); } catch { /* already gone */ }
        await ended;
        for (let i = 0; i < 20; i += 1) {
            try { await rm(work, { recursive: true, force: true }); return; } catch { await new Promise((r) => setTimeout(r, 250)); }
        }
        console.log(`NOTE: could not remove ${work} — please delete it by hand.`);
    };

    try {
        /* ⚠️ Read the port the browser CHOSE (`--remote-debugging-port=0`
              writes it to DevToolsActivePort). Naming one risks a collision,
              which looks exactly like the browser failing to start. */
        let wsUrl = null;
        for (let i = 0; i < 200 && wsUrl === null; i += 1) {
            try {
                const port = (await readFile(path.join(work, 'DevToolsActivePort'), 'utf8')).split('\n')[0].trim();
                wsUrl = (await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl;
            } catch { await new Promise((r) => setTimeout(r, 100)); }
        }
        if (wsUrl === null) { throw new Error(`the browser at ${browserPath} never opened its debugging port`); }

        const socket = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            socket.addEventListener('open', resolve);
            socket.addEventListener('error', () => reject(new Error('could not attach to the browser')));
        });
        let id = 0;
        const waiting = new Map();
        const events = [];
        socket.addEventListener('message', (e) => {
            const m = JSON.parse(e.data);
            if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } else if (m.method) { events.push(m); }
        });
        let session = null;
        const send = (method, params = {}, inSession = true) => new Promise((resolve) => {
            id += 1;
            waiting.set(id, resolve);
            socket.send(JSON.stringify({ id, method, params, ...(inSession && session ? { sessionId: session } : {}) }));
        });
        const { targetId } = (await send('Target.createTarget', { url: 'about:blank' }, false)).result;
        session = (await send('Target.attachToTarget', { targetId, flatten: true }, false)).result.sessionId;
        await send('Page.enable');
        await send('Network.enable');
        const [cookieName, ...cookieValue] = COOKIE.split('=');
        await send('Network.setCookie', { name: cookieName, value: cookieValue.join('='), domain: new URL(BASE).hostname, path: '/' });

        const evaluate = async (expression) => {
            const answer = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
            if (!answer.result || !answer.result.result || answer.result.exceptionDetails) {
                throw new Error(`the page could not be measured: ${JSON.stringify(answer.result && answer.result.exceptionDetails).slice(0, 300)}`);
            }
            return answer.result.result.value;
        };
        const load = async (page, width) => {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
            events.length = 0;
            const nav = await send('Page.navigate', { url: BASE + page });
            if (nav.result && nav.result.errorText) { throw new Error(`${page} did not load: ${nav.result.errorText}`); }
            for (let i = 0; i < 150 && !events.some((e) => e.method === 'Page.loadEventFired'); i += 1) {
                await new Promise((r) => setTimeout(r, 100));
            }
            await new Promise((r) => setTimeout(r, 300));
        };

        // A REAL key press, through the browser's input pipeline, so the page
        // sees what a person's keyboard sends (Enter needs its `text` or no
        // click is generated).
        const key = async (name, code, vk) => {
            for (const type of ['keyDown', 'keyUp']) {
                await send('Input.dispatchKeyEvent', {
                    type, key: name, code, windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk,
                    ...(name === 'Enter' && type === 'keyDown' ? { text: '\r' } : {}),
                });
            }
            await new Promise((r) => setTimeout(r, 200));
        };
        const mouseTo = async (x, y) => {
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
            await new Promise((r) => setTimeout(r, 40));
        };

        console.log(`Top bar, signed in as the ${LABEL} account, in ${browserPath.split('/').pop()}`);
        let premiseChecked = false;
        for (const page of PAGES) {
            for (const width of WIDTHS) {
                await load(page, width);
                const closed = await evaluate(CLOSED);
                if (closed.noBar === true) {
                    check(`${page} @${width}: the page draws the top bar`, false, 'no nav.portal-navbar on the page — not signed in, or the page failed');
                    continue;
                }
                check(`${page} @${width}: with every menu closed the page is no wider than the screen`,
                    closed.doc <= width + 1, `the page is ${closed.doc}px wide on a ${width}px screen`);
                if (closed.collapsed === false) {
                    check(`${page} @${width}: every entry on the bar is one line`,
                        closed.twoLines.length === 0, `broken over two lines: ${closed.twoLines.join(', ')}`);
                }
                if (SWITCHER === true && page === PAGES[0]) {
                    check(`${page} @${width}: premise — the organisation switcher is on the bar`, closed.switcher === true,
                        'no "Switch organisation" button, so the several-organisations case is not being tested');
                }
                check(`${page} @${width}: the organisation's name is readable, or left to the switcher — never a sliver`,
                    (closed.nameShown === true && closed.nameWidth >= 40)
                        || (closed.nameWidth === 0 && closed.switcherNames === true),
                    `name ${closed.nameShown ? 'drawn' : 'not drawn'} at ${closed.nameWidth}px; switcher ${closed.switcherShown ? 'showing' : 'not showing'}`);

                const open = await evaluate(OPEN_MENU(width));
                if (open.noMenu === true) {
                    check(`${page} @${width}: the bar has an Apps menu`, false, 'no #portalNav Apps button');
                    continue;
                }
                if (premiseChecked === false) {
                    // 🧪 THE PREMISE. Without a crowded bar this whole test is
                    //    vacuous: the fault needs a dozen or more entries to
                    //    show at 1024, which is why every earlier audit missed
                    //    it. Checked once, on the first page measured.
                    check(`premise: the Apps menu holds at least ${MIN_APPS} apps (it holds ${open.entries})`,
                        open.entries >= MIN_APPS, 'the test world did not switch enough apps on, so nothing here proves anything');
                    premiseChecked = true;
                }
                check(`${page} @${width}: the Apps menu opens and says so (aria-expanded)`,
                    open.shown !== 'none' && open.expanded === 'true', `display ${open.shown}, aria-expanded ${open.expanded}`);
                check(`${page} @${width}: with the Apps menu open the page is still no wider than the screen, and the menu is on it`,
                    open.doc <= width + 1 && open.left >= 0 && open.right <= width + 1,
                    `page ${open.doc}px, menu from ${open.left} to ${open.right}, screen ${width}px`);
                check(`${page} @${width}: nothing on the page is drawn on top of the open menu`,
                    open.coveredCount === 0, `${open.coveredCount} entr(ies) covered, e.g. ${open.covered.join('; ')}`);

                if (page === PAGES[0]) {
                    await load(page, width);   // a fresh page: the open menu would sit in the way
                    const notices = await evaluate(NOTICES);
                    if (notices.noBanner === true) {
                        check(`${page} @${width}: the cookie banner is showing (premise)`, false,
                            'no #portal-cookie-banner: the account already answered it, or the banner is switched off, so the overlap cannot be tested');
                    } else if (notices.noPrompt === true) {
                        check(`${page} @${width}: the install prompt appears when the browser offers it`, false,
                            '#portal-install-prompt stayed hidden after beforeinstallprompt');
                    } else {
                        check(`${page} @${width}: the cookie banner and the install prompt do not overlap`,
                            notices.apart === true, `banner ${notices.banner.join('-')}, prompt ${notices.prompt.join('-')} (top-bottom)`);
                    }
                }
            }
        }
        // ⌨️ THE KEYBOARD, with scripts on, at a wide and a narrow screen.
        for (const width of [1440, 1024, 390]) {
            await load(PAGES[0], width);
            const narrow = width < 992;
            if (narrow === true) {
                await evaluate("document.querySelector('nav.portal-navbar .navbar-toggler').click()");
                await new Promise((r) => setTimeout(r, 500));
            }
            await evaluate("document.getElementById('portalNavApps').focus()");
            let st = await evaluate(APPS_STATE);
            check(`keyboard @${width}: focus alone on the Apps button shows nothing, and says so`,
                st.focusOnButton === true && st.shown === false && st.expanded === 'false',
                `focus on button ${st.focusOnButton}, menu ${st.shown ? 'SHOWN' : 'hidden'}, aria-expanded ${st.expanded}`);
            await key('Enter', 'Enter', 13);
            st = await evaluate(APPS_STATE);
            check(`keyboard @${width}: Enter opens the Apps menu with aria-expanded="true"`,
                st.shown === true && st.expanded === 'true', `menu ${st.shown ? 'shown' : 'HIDDEN'}, aria-expanded ${st.expanded}`);
            await key('Escape', 'Escape', 27);
            st = await evaluate(APPS_STATE);
            check(`keyboard @${width}: Escape hides it again, says aria-expanded="false" and leaves focus on the button`,
                st.shown === false && st.expanded === 'false' && st.focusOnButton === true,
                `menu ${st.shown ? 'STILL SHOWN' : 'hidden'}, aria-expanded ${st.expanded}, focus on button ${st.focusOnButton}`);
            if (narrow === false) {
                // ⌨️🖱️ Focus alone, and a resting pointer, open NO menu on the
                //    bar while Bootstrap works — checked on every menu button,
                //    because each one had the same fallback rule.
                const premise = await evaluate(MARK);
                check(`premise @${width}: Bootstrap's menus loaded and the page says so (data-portal-menus)`,
                    premise.bootstrap === true && premise.mark === 'on', `bootstrap ${premise.bootstrap}, mark ${premise.mark}`);
                const count = (await evaluate(TOGGLE_STATE(0, false))).count;
                check(`premise @${width}: the bar has more than one menu button (it has ${count})`, count >= 2,
                    'with one button the "every menu" checks below are only the Apps check again');
                const focusOpened = [], hoverOpened = [];
                for (let i = 0; i < count; i += 1) {
                    const f = await evaluate(TOGGLE_STATE(i, true));
                    if (f.shown === true || f.expanded !== 'false') { focusOpened.push(`${f.label} (aria-expanded ${f.expanded})`); }
                    await evaluate('document.activeElement && document.activeElement.blur()');
                    await mouseTo(f.x, f.y);
                    const h = await evaluate(TOGGLE_STATE(i, false));
                    if (h.shown === true) { hoverOpened.push(h.label); }
                    await mouseTo(1, 1);
                }
                check(`keyboard @${width}: focus alone on ANY menu button on the bar shows nothing`,
                    focusOpened.length === 0, `opened on focus: ${focusOpened.join(', ')}`);
                check(`pointer @${width}: resting on ANY menu button opens nothing while Bootstrap works`,
                    hoverOpened.length === 0, `opened on hover: ${hoverOpened.join(', ')}`);
            }
        }

        // 🚫 SCRIPTS OFF. The menus then open on hover and on keyboard focus,
        //    the fallback, and that is the only way to any app. Checked at
        //    the two wide widths, where the menu hangs from the whole bar.
        await send('Emulation.setScriptExecutionDisabled', { value: true });
        for (const width of [1440, 1024]) {
            await load(PAGES[0], width);
            const st0 = await evaluate(APPS_STATE);
            if (st0.noMenu === true) { check(`no scripts @${width}: the bar has an Apps menu`, false); continue; }
            // Hover the button; the menu opens; THEN read where its first entry is.
            await mouseTo(st0.button.x, st0.button.y);
            const opened = await evaluate(APPS_STATE);
            check(`no scripts @${width}: hovering the Apps button opens the menu`, opened.shown === true && opened.first !== null,
                `menu ${opened.shown ? 'shown' : 'HIDDEN'}`);
            if (opened.shown !== true || opened.first === null) { await mouseTo(1, 1); continue; }
            // Straight down from the button in 2px steps, then across to the first entry.
            let lostAt = null;
            for (let y = Math.round(opened.button.y); y <= Math.round(opened.first.y); y += 2) {
                await mouseTo(opened.button.x, y);
                if ((await evaluate(APPS_STATE)).shown !== true) { lostAt = y; break; }
            }
            if (lostAt === null) {
                await mouseTo(opened.first.x, opened.first.y);
                if ((await evaluate(APPS_STATE)).shown !== true) { lostAt = `moving across to the first entry`; }
            }
            check(`no scripts @${width}: a mouse can travel from the Apps button to its first entry without the menu closing`,
                lostAt === null, `the menu closed at y=${lostAt} (button ends at ${Math.round(opened.button.bottom)})`);
            const onEntry = await evaluate(`(() => { const e = document.elementFromPoint(${opened.first.x}, ${opened.first.y});
                return e !== null && e.closest('.portal-nav-apps-menu .dropdown-item') !== null; })()`);
            check(`no scripts @${width}: the pointer lands ON the first app entry, so it can be clicked`, onEntry === true);
            await mouseTo(1, 1);
            await evaluate("document.getElementById('portalNavApps').focus()");
            const focused = await evaluate(APPS_STATE);
            check(`no scripts @${width}: keyboard focus on the Apps button opens the menu (the only keyboard way in without scripts)`,
                focused.shown === true, `menu ${focused.shown ? 'shown' : 'HIDDEN'}`);
        }
        await send('Emulation.setScriptExecutionDisabled', { value: false });

        // 📦 SCRIPTS ON, BOOTSTRAP BLOCKED (#822 round 2). Both copies of the
        //    bundle failing — the CDN and the local one, which a checkout
        //    without tools/download-cdn-fallbacks.sh does not have — left
        //    every menu dead, because the "menus work" mark was set by the
        //    head script whatever happened next. The fallback must apply.
        await send('Network.setBlockedURLs', { urls: ['*bootstrap.bundle*'] });
        for (const width of [1440, 1024]) {
            await load(PAGES[0], width);
            const m = await evaluate(MARK);
            check(`no Bootstrap @${width}: premise — the bundle really is blocked`, m.bootstrap === false,
                'window.bootstrap exists, so the block did not take and nothing below proves anything');
            check(`no Bootstrap @${width}: the page does NOT claim its menus work`, m.mark === null, `data-portal-menus="${m.mark}"`);
            const st0 = await evaluate(APPS_STATE);
            if (st0.noMenu === true) { check(`no Bootstrap @${width}: the bar has an Apps menu`, false); continue; }
            await mouseTo(st0.button.x, st0.button.y);
            const opened = await evaluate(APPS_STATE);
            check(`no Bootstrap @${width}: hovering the Apps button still opens the menu`, opened.shown === true,
                `menu ${opened.shown ? 'shown' : 'HIDDEN'}`);
            await mouseTo(1, 1);
            const count = (await evaluate(TOGGLE_STATE(0, false))).count;
            const dead = [];
            for (let i = 0; i < count; i += 1) {
                const f = await evaluate(TOGGLE_STATE(i, true));
                if (f.shown !== true) { dead.push(f.label); }
                await evaluate('document.activeElement && document.activeElement.blur()');
            }
            check(`no Bootstrap @${width}: keyboard focus opens EVERY menu on the bar (${count}), so each is still reachable`,
                count >= 2 && dead.length === 0, count < 2 ? `only ${count} menu button(s)` : `stayed shut: ${dead.join(', ')}`);
        }
        await send('Network.setBlockedURLs', { urls: [] });

        socket.close();
    } finally {
        await stop();
    }
    console.log(`\n${passed} passed, ${failed} failed (${LABEL})`);
    process.exit(failed === 0 && passed > 0 ? 0 : 1);
}

main().catch((e) => {
    console.error(`REFUSED: ${e && e.message ? e.message : e}`);
    process.exit(2);
});
