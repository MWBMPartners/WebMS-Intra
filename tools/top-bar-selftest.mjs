/**
 * -----------------------------------------------------------------------------
 * File:        tools/top-bar-selftest.mjs
 * Description: The browser half of tools/top-bar-selftest.php. Signed in with
 *              the cookie it is given, it loads real portal pages in a real
 *              browser at five screen widths and checks that the shared top
 *              bar fits the screen — closed, and with its Apps menu open —
 *              and that the cookie banner and the "Install app" prompt never
 *              sit on top of each other. Also (#832) that a keyboard user
 *              can see where they are on the bar, and (#833) that four whole
 *              pages given with --wide-pages fit a phone.
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
 *   * A focus indicator drawn any other way than an outline or a box-shadow
 *     (a border, a background, an underline). It would read as "nothing" and
 *     fail — the safe direction — so a change of style needs this test
 *     changed with it. It also cannot see the ring's SIZE beyond "at least
 *     2px", nor whether something else on the bar covers part of it.
 *   * Whole pages other than the four it is given, or at widths other than
 *     320 and 390, or with data other than the rows the PHP half adds.
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
 *   * The focus ring (#832): Tab along the bar in the light and dark themes,
 *     each with and without the colour-blind palette, at 1440 and 390; every
 *     focused entry must show a ring at least 3:1 against the bar and against
 *     its own background. A mouse click must show no ring. It measures the
 *     colours the browser computed; it cannot see an organisation's own
 *     primary colour, which the ring deliberately does not use.
 *   * Whole pages (#833): each --wide-pages page at 320 and 390, with every
 *     collapsed section opened, must be no wider than the screen — and must
 *     show the test data, so an empty or refused page cannot pass.
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
// --wide-pages=/a|text;/b|text (#833): pages whose WHOLE BODY must fit a phone,
// each with a piece of text that must be on it — the real-looking data the
// PHP half put there, so a page that came back empty or refused cannot pass
// for one that fits. tools/top-bar-selftest.php passes these for the
// administrator runs only: /settings and /admin/users are administrators'
// pages, and a member's /attendance and /photos are not measured (#851).
const WIDE_PAGES = (arg('wide-pages', '')).split(';').filter(Boolean).map((p) => {
    const bar = p.indexOf('|');
    return bar === -1 ? { page: p, premise: '' } : { page: p.slice(0, bar), premise: p.slice(bar + 1) };
});
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

// 🎯 THE FOCUS RING ON THE FOCUSED ENTRY (#832). What a keyboard user sees
//    around the entry they have tabbed to, and its contrast (WCAG 2.x's
//    relative-luminance formula) against the bar behind it and against the
//    entry's own background (the current page's entry is highlighted).
//    * Every colour goes through a 1x1 canvas, which turns ANY CSS colour the
//      browser computes — `rgb()`, `rgba()`, and the `color(srgb …)` that
//      color-mix() produces — into the same four bytes. Parsing the text
//      ourselves would miss whichever form we did not think of.
//    * The ring is the OUTLINE when one is drawn at least 2px wide; failing
//      that, the first box-shadow colour (Bootstrap's own focus style, which
//      is what the bar showed before #832 — so the "before" state measures as
//      a real, low, number rather than as "no ring"); failing both, nothing,
//      which counts as 1:1.
//    * A see-through colour is laid over the bar first, because that is the
//      colour a person sees.
const FOCUS_RING = `(() => {
  const a = document.activeElement, nav = document.querySelector('nav.portal-navbar');
  if (!a || !nav || !nav.contains(a)) return { inNav: false };
  const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  const bytes = (css) => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = '#000'; ctx.fillStyle = css; ctx.fillRect(0, 0, 1, 1);
    const d = ctx.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2], d[3] / 255]; };
  const over = (top, under) => [0, 1, 2].map(i => top[i] * top[3] + under[i] * (1 - top[3])).concat(1);
  const lum = (c) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (x, y) => { const p = lum(x), q = lum(y); return (Math.max(p, q) + 0.05) / (Math.min(p, q) + 0.05); };
  const cs = getComputedStyle(a);
  const bar = over(bytes(getComputedStyle(nav).backgroundColor), [255, 255, 255, 1]);
  const own = over(bytes(cs.backgroundColor), bar);
  let ring = null, how = 'nothing';
  if (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2) { ring = bytes(cs.outlineColor); how = 'outline ' + cs.outlineWidth; }
  else if (cs.boxShadow !== 'none') { const m = cs.boxShadow.match(/(rgba?\\([^)]*\\)|color\\([^)]*\\))/); if (m) { ring = bytes(m[1]); how = 'shadow'; } }
  const seen = ring === null ? bar : over(ring, bar);
  return { inNav: true, focusVisible: a.matches(':focus-visible'),
    label: (a.id || a.getAttribute('aria-label') || a.textContent.trim()).replace(/\\s+/g, ' ').slice(0, 32),
    how, vsBar: Math.round(ratio(seen, bar) * 100) / 100, vsOwn: Math.round(ratio(seen, own) * 100) / 100,
    theme: document.documentElement.getAttribute('data-bs-theme'), cb: document.documentElement.getAttribute('data-portal-cb') };
})()`;

// 📏 THE WIDEST THING ON A PAGE (#833). Every section a person could open is
//    opened first (the settings page keeps most of its rows in closed
//    accordion sections, and a closed section is not measured at all), then
//    the page's width is read and, when it is too wide, the OUTERMOST element
//    sticking out past the screen is named — its parent fits, it does not —
//    so a failure says where to look. The cookie banner and the install
//    prompt are left out of the naming: they are fixed to the screen and grow
//    WITH an over-wide page, so they are a symptom, never the cause.
const PAGE_WIDTH = (asked, premise) => `(async () => {
  document.querySelectorAll('main .collapse').forEach(e => e.classList.add('show'));
  await new Promise(r => setTimeout(r, 150));
  const W = ${asked};
  const culprits = [];
  for (const el of document.querySelectorAll('main *')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.right <= W + 1) continue;
    const p = el.parentElement;
    if (p !== null && p.getBoundingClientRect().right > W + 1) continue;
    const chain = []; let e = el;
    for (let i = 0; i < 4 && e !== null && e.tagName !== 'MAIN'; i += 1) {
      chain.unshift(e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className.trim() !== '' ? '.' + e.className.trim().split(/\\s+/).slice(0, 2).join('.') : ''));
      e = e.parentElement;
    }
    culprits.push(chain.join(' > ') + ' (' + Math.round(r.right) + 'px: "' + el.textContent.trim().replace(/\\s+/g, ' ').slice(0, 40) + '")');
  }
  return {
    doc: document.documentElement.scrollWidth,
    culprits: culprits.slice(0, 3),
    // ⚠️ Looked for INSIDE a list row or a card, never in the whole page:
    //    /attendance prints every service type in its filter drop-down too,
    //    so with the whole body searched an attendance page with NO row
    //    passed this premise (#833 round 1, planted: an emptied list stayed
    //    green). A row or a card is where the data the width depends on is.
    premise: [...document.querySelectorAll('main .portal-data-row:not(.portal-data-header), main .card')]
      .some(e => e.textContent.includes(${JSON.stringify(premise)})),
    title: document.title,
  };
})()`;

// 🧩 WHAT THE ROW RULE MUST NOT DO (#833 round 1). The first version of the
//    data-list rule broke words that fit their ROW but not a narrow CELL:
//    "£12,345.6" / "7" in a desktop money column at 1024px, and "Leadershi/p"
//    and the badge "Propo/sed" in a phone's fraction-of-a-row cell. These two
//    rows are copied from the pages that showed it — expenses/treasury
//    (`col-12 col-md-1` money column) and venues/statuses (bare `col-3`,
//    `col-2` cells) — and placed inside a REAL portal page, so the real
//    stylesheet and Bootstrap lay them out. A FIXTURE rather than the real
//    pages on purpose: the property is the stylesheet's, and seeding a paid
//    expense claim and a venue status would put rows in money and booking
//    tables for a test about line breaks. The cost: if those pages change
//    their markup this copy does not follow; the shapes it holds are the two
//    that broke.
//    "Split" is measured, not guessed: a Range over the text gives one
//    rectangle per line the text sits on, so more than one distinct line top
//    means the word or figure was broken.
const ROW_FIXTURE = `(() => {
  const main = document.querySelector('main');
  main.innerHTML = '<div class="container">'
   + '<div class="portal-data-list">'
   + '<div class="portal-data-row portal-data-header d-none d-md-flex"><div class="col-md-1">ID</div><div class="col-md-3">Title</div><div class="col-md-2">Claimant</div><div class="col-md-2">Department</div><div class="col-md-1 text-end">Total &pound;</div><div class="col-md-2 text-end">Submitted</div><div class="col-md-1 text-end"></div></div>'
   + '<div class="portal-data-row"><div class="col-12 col-md-1"><span class="d-md-none fw-semibold">ID: </span>#1042</div>'
   + '<div class="col-12 col-md-3"><span class="d-md-none fw-semibold">Title: </span>Hall heating repair and parts</div>'
   + '<div class="col-12 col-md-2"><span class="d-md-none fw-semibold">Claimant: </span>Aaliyah Montgomery-Fitzwilliam</div>'
   + '<div class="col-12 col-md-2"><span class="d-md-none fw-semibold">Dept: </span>Buildings and Grounds</div>'
   + '<div class="col-12 col-md-1 text-md-end"><span class="d-md-none fw-semibold">Total: </span><span id="fx-money">&pound;12,345.67</span></div>'
   + '<div class="col-12 col-md-2 text-md-end small text-secondary"><span class="d-md-none fw-semibold">Submitted: </span>7 Nov 2026</div>'
   + '<div class="col-12 col-md-1 text-md-end mt-2 mt-md-0"><button class="btn btn-sm btn-outline-success">Pay</button></div></div>'
   + '</div>'
   + '<div class="portal-data-list mt-3">'
   + '<div class="portal-data-row align-items-center"><div class="col-3"><span class="badge" style="background-color:#888">&nbsp;&nbsp;</span> <strong class="ms-1" id="fx-word">Leadership</strong></div>'
   + '<div class="col-2"><span class="badge bg-warning" id="fx-badge">Proposed</span></div>'
   + '<div class="col-2 small">yes / no</div><div class="col-1"><span class="badge bg-light text-dark border">12</span></div>'
   + '<div class="col-4 text-end"><button class="btn btn-sm btn-outline-secondary">Edit</button></div></div>'
   + '</div>'
   // #833 round 2: a full-row cell holding a note box and a button side by
   // side, copied from /giving/reconcile/view's "Ignore" form. The rule overflow-wrap:
   // anywhere let the button be squeezed below its own label and split it.
   + '<div class="portal-data-list mt-3">'
   + '<div class="portal-data-row"><div class="col-12 col-md-2"><form class="d-flex gap-1">'
   + '<input type="text" class="form-control form-control-sm" placeholder="Note (optional)">'
   + '<button type="button" class="btn btn-outline-secondary btn-sm" id="fx-button">Ignore</button></form></div></div>'
   + '</div>'
   // #852 round 2: a badge alone in a row's ASIDE beside a long title, copied
   // from /admin/calendar/reminders. Above the phone widths the row is a flex
   // box, and a badge allowed to wrap there lets the aside shrink and split
   // "2/3 reminders sent" over two lines; before #852 the title wrapped.
   + '<div class="portal-data-list mt-3">'
   + '<div class="portal-data-row"><div class="portal-data-row-main"><strong>Sunday Morning Family Worship and Holy Communion with the Baptism of Several Children and a Shared Lunch Afterwards in the Church Hall for Everyone</strong>'
   + '<div class="text-muted small">Sunday 8 Nov, 10:30</div></div>'
   + '<div class="portal-data-row-aside"><span class="badge bg-info text-dark" id="fx-aside-badge">2/3 reminders sent</span></div></div>'
   + '</div>'
   // #833 round 2: the message /settings shows after saving a setting with a
   // long name — one "word" with nowhere a browser may break it.
   + '<div class="alert alert-success alert-dismissible fade show mt-3" id="fx-alert">'
   + 'Setting "safeguarding.dbs_required_for_coordinators" updated.'
   + '<button type="button" class="btn-close" aria-label="Close"></button></div>'
   + '</div>';
  return true;
})()`;
// #852: the page list prints ONE "…" per run of hidden pages. Answers how
// many times two "…" sit side by side (0 when right), and how many "…" there
// are, so a page whose list hides nothing cannot pass for one that is right.
// (A first version required at least eight entries, a length taken from the
// FAULTY list: a correct page 1 of /admin/users has seven, « 1 2 … 8 9 ».)
// Also how many page numbers jump by more than one with NO "…" between them
// (round 1 of #852's check): counting doubles alone could not see a loop that
// dropped the SECOND "…" on a middle page, because page 1 has only one gap.
const ELLIPSES = `(() => {
  const items = [...document.querySelectorAll('main .pagination .page-item')].map(li => li.textContent.trim());
  let doubled = 0, unmarked = 0, last = null, gapSince = false;
  for (let i = 0; i < items.length; i += 1) {
    const x = items[i];
    if (x === '\u2026') { if (i > 0 && items[i - 1] === '\u2026') { doubled += 1; } gapSince = true; continue; }
    if (x === '' || /^[0-9]+$/.test(x) === false) { continue; }
    const n = Number(x);
    if (last !== null && n - last > 1 && gapSince === false) { unmarked += 1; }
    last = n; gapSince = false;
  }
  return { items: items.length, gaps: items.filter(x => x === '\u2026').length, doubled, unmarked };
})()`;
// #852: a dismissible alert's words must stay clear of its close button.
const ALERT_CLEAR = (id) => `(() => {
  const el = document.getElementById(${JSON.stringify(id)});
  const close = el === null ? null : el.querySelector('.btn-close');
  if (el === null || close === null) return null;
  let textRight = 0;
  for (const node of el.childNodes) {
    if (node.nodeType !== 3 || node.textContent.trim() === '') continue;
    const r = document.createRange(); r.selectNodeContents(node);
    for (const x of r.getClientRects()) { if (x.width > 0) textRight = Math.max(textRight, x.right); }
  }
  return { textRight: Math.round(textRight), closeLeft: Math.round(close.getBoundingClientRect().left) };
})()`;
// #852: a badge naming the page's test data must stay inside its own cell.
const BADGE_IN_CELL = (text) => `(() => {
  const badge = [...document.querySelectorAll('main .portal-data-row .badge')].find(b => b.textContent.includes(${JSON.stringify(text)}));
  if (badge === undefined) return null;
  const cell = badge.parentElement.getBoundingClientRect();
  return { badgeRight: Math.round(badge.getBoundingClientRect().right), cellRight: Math.round(cell.right) };
})()`;
// How far right an element's TEXT reaches, against the screen it must fit
// (#833 round 2). ⚠️ Not the element's box: a word that will not break spills
// out past its box without widening it, so the first version of this measured
// the box and could never fail — planted, removing the alert rule left it
// green. A Range over the contents gives one rectangle per line of text, and
// the furthest right of those is where the words really end.
const RIGHT = (id) => `(() => {
  const el = document.getElementById(${JSON.stringify(id)});
  if (el === null) return -1;
  const r = document.createRange(); r.selectNodeContents(el);
  const rights = [...r.getClientRects()].filter(x => x.width > 0).map(x => x.right);
  return Math.round(Math.max(el.getBoundingClientRect().right, ...rights));
})()`;
const LINES = (id) => `(() => {
  const el = document.getElementById(${JSON.stringify(id)});
  if (el === null) return -1;
  const r = document.createRange(); r.selectNodeContents(el);
  const tops = new Set([...r.getClientRects()].filter(x => x.width > 0).map(x => Math.round(x.top)));
  return tops.size;
})()`;

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
        // ⏱️ Every command to the browser has a deadline. Without one, a
        //    command the browser never answers left the whole run waiting for
        //    ever, printing nothing (see "Keep THIS tab" below). A stall is now
        //    a refusal (exit 2) naming the command — never a pass.
        const send = (method, params = {}, inSession = true) => new Promise((resolve, reject) => {
            id += 1;
            const mine = id;
            const timer = setTimeout(() => {
                waiting.delete(mine);
                reject(new Error(`the browser did not answer ${method} within 60 seconds`));
            }, 60000);
            waiting.set(mine, (m) => { clearTimeout(timer); resolve(m); });
            socket.send(JSON.stringify({ id: mine, method, params, ...(inSession && session ? { sessionId: session } : {}) }));
        });
        const { targetId } = (await send('Target.createTarget', { url: 'about:blank' }, false)).result;
        session = (await send('Target.attachToTarget', { targetId, flatten: true }, false)).result.sessionId;
        await send('Page.enable');
        await send('Network.enable');
        /* 👁️ Keep THIS tab the visible, focused one. Edge starts with a tab of
              its own, and on 7 November 2026 one run found the test's tab
              reporting `visibilityState: "hidden"` part-way through: a hidden
              tab's timers are throttled and its key presses were never
              answered, so the run sat still for minutes with nothing failing
              (#832's build, measured by attaching to the stalled browser).
              Focus emulation also makes `:focus-visible` behave as it does in
              a window a person is looking at, which the focus-ring checks
              depend on. */
        await send('Page.bringToFront');
        await send('Emulation.setFocusEmulationEnabled', { enabled: true });
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

        // 🎯 THE FOCUS RING (#832). Tab along the bar the way a keyboard user
        //    does — REAL Tab presses, because `:focus-visible` is decided by
        //    how focus arrived and a scripted focus() is not the same thing —
        //    in the light and dark themes, each with and without the
        //    colour-blind palette, wide and narrow (on a phone the entries are
        //    inside the opened menu). Every entry focused on the bar must show
        //    a ring at least 3:1 against the bar AND against its own
        //    background. The theme is chosen the way the bar's own buttons
        //    choose it, through localStorage, which header.php reads before
        //    the page is drawn; it is cleared again afterwards.
        for (const width of [1440, 390]) {
            for (const [theme, cb] of [['light', 'off'], ['light', 'on'], ['dark', 'off'], ['dark', 'on']]) {
                await load(PAGES[0], width);
                await evaluate(`localStorage.setItem('portal-theme', '${theme}'); localStorage.setItem('portal-cb', '${cb}')`);
                await load(PAGES[0], width);
                if (width < 992) {
                    await evaluate("document.querySelector('nav.portal-navbar .navbar-toggler').click()");
                    await new Promise((r) => setTimeout(r, 500));
                }
                await evaluate('document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0)');
                const rings = [];
                let left = false;
                let premise = null;
                for (let i = 0; i < 60 && left === false; i += 1) {
                    await key('Tab', 'Tab', 9);
                    const r = await evaluate(FOCUS_RING);
                    if (r.inNav === true) {
                        rings.push(r);
                        if (premise === null) { premise = r; }
                    } else if (rings.length > 0) {
                        left = true;   // tabbed past the bar's last entry
                    }
                }
                const where = `${theme} theme, colour-blind palette ${cb}, @${width}`;
                check(`focus ring, ${where}: premise — the theme took, and Tab reached at least 4 entries on the bar (it reached ${rings.length})`,
                    premise !== null && premise.theme === theme && (premise.cb === 'on') === (cb === 'on') && rings.length >= 4,
                    premise === null ? 'Tab never reached the bar' : `data-bs-theme ${premise.theme}, data-portal-cb ${premise.cb}`);
                const faint = rings.filter((r) => r.focusVisible !== true || r.vsBar < 3 || r.vsOwn < 3)
                    .map((r) => `${r.label} (${r.how}, ${r.vsBar}:1 against the bar, ${r.vsOwn}:1 against itself${r.focusVisible ? '' : ', NOT :focus-visible'})`);
                const lowest = rings.length > 0 ? Math.min(...rings.map((r) => Math.min(r.vsBar, r.vsOwn))) : 0;
                check(`focus ring, ${where}: every focused entry shows a ring of at least 3:1 (lowest ${lowest}:1)`,
                    rings.length > 0 && faint.length === 0, `too faint: ${faint.slice(0, 4).join('; ')}`);
            }
        }
        await evaluate("localStorage.removeItem('portal-theme'); localStorage.removeItem('portal-cb')");
        // 🖱️ …and a mouse click shows nothing new: the ring is for the
        //    keyboard (`:focus-visible`), so pressing the Apps button with the
        //    pointer must leave it without one. A real press, through the
        //    browser's input pipeline, for the same reason as the Tab presses.
        await load(PAGES[0], 1440);
        const appsAt = await evaluate(APPS_STATE);
        if (appsAt.noMenu !== true) {
            for (const type of ['mousePressed', 'mouseReleased']) {
                await send('Input.dispatchMouseEvent', { type, x: appsAt.button.x, y: appsAt.button.y, button: 'left', clickCount: 1 });
            }
            await new Promise((r) => setTimeout(r, 250));
            const clicked = await evaluate(FOCUS_RING);
            check('focus ring: a mouse click on the Apps button gives it focus but shows no keyboard ring',
                clicked.inNav === true && clicked.focusVisible === false && clicked.how.startsWith('outline') === false,
                `focus on the bar ${clicked.inNav}, :focus-visible ${clicked.focusVisible}, drawn: ${clicked.how}`);
        }

        // 📱 WHOLE PAGES ON A PHONE (#833). Not the bar this time: the body
        //    of each page named by --wide-pages, at the two phone widths,
        //    with the real-looking data the PHP half put there.
        for (const { page, premise } of WIDE_PAGES) {
            for (const width of [320, 390]) {
                await load(page, width);
                const r = await evaluate(PAGE_WIDTH(width, premise));
                check(`${page} @${width}: premise — the page shows the test data ("${premise}")`, premise !== '' && r.premise === true,
                    `not found on "${r.title}": the page was refused or empty, so its width proves nothing`);
                check(`${page} @${width}: the whole page, every section opened, is no wider than the screen`,
                    r.doc <= width + 1,
                    `the page is ${r.doc}px wide; sticking out: ${r.culprits.length > 0 ? r.culprits.join('; ') : 'nothing inside <main> — look at the header or footer'}`);
                if (page.startsWith('/attendance') || page.startsWith('/admin/users')) {
                    // Page 1 has one run of hidden pages; the two pages chosen as
                    // the MIDDLE of their list (?page=6 of attendance, ?page=5 of
                    // users) have two, which is the only place a missing second
                    // "…" shows. Other pages are named by the address only — users'
                    // page 4 lists 1-5 and so has ONE run, and asking it for two
                    // went red on correct code. The middle pages are named
                    // exactly, never as "any address with page=" (#483).
                    const e = await evaluate(ELLIPSES);
                    const wantGaps = (page === '/attendance?page=6' || page === '/admin/users?page=5') ? 2 : 1;
                    check(`${page} @${width}: the page list prints exactly one "…" per run of hidden pages — none doubled, none missing (#852)`,
                        e.gaps >= wantGaps && e.doubled === 0 && e.unmarked === 0,
                        `${e.items} entries, ${e.gaps} "…" (want at least ${wantGaps}), ${e.doubled} doubled, ${e.unmarked} gap(s) with no "…"`);
                }
            }
        }

        // 🧩 WHAT THE ROW RULE MUST NOT BREAK (#833 round 1): a money figure
        //    in a narrow desktop column, and a word and a one-word badge in a
        //    phone's fraction-of-a-row cell; and (round 2) a button's label
        //    beside a note box in a full-row cell. Plus, from the separate
        //    `.alert` rule, an alert quoting a long setting name. See
        //    ROW_FIXTURE for why a copy. ⚠️ The alert check can only FAIL at
        //    320: at 390 that name fits the screen whether or not the rule
        //    breaks it (round 3 of the check; #851).
        for (const width of [1024, 768, 390, 320]) {
            await load(PAGES[0], width);
            await evaluate(ROW_FIXTURE);
            await new Promise((r) => setTimeout(r, 150));
            const lines = {
                money: await evaluate(LINES('fx-money')),
                word: await evaluate(LINES('fx-word')),
                badge: await evaluate(LINES('fx-badge')),
                button: await evaluate(LINES('fx-button')),
                aside: await evaluate(LINES('fx-aside-badge')),
            };
            const alertRight = await evaluate(RIGHT('fx-alert'));
            check(`row rule @${width}: premise — the copied rows are on the page`,
                lines.money > 0 && lines.word > 0 && lines.badge > 0 && lines.button > 0 && lines.aside > 0 && alertRight > 0, JSON.stringify({ ...lines, alertRight }));
            if (width >= 768) {
                check(`row rule @${width}: a money figure in a narrow list column stays on one line ("£12,345.67")`,
                    lines.money === 1, `it is broken over ${lines.money} lines`);
                check(`row rule @${width}: a badge alone beside a long title ("2/3 reminders sent") stays on one line; the title wraps instead (#852 round 2)`,
                    lines.aside === 1, `the badge is on ${lines.aside} line(s)`);
            } else {
                check(`row rule @${width}: a word ("Leadership") and a one-word badge ("Proposed") in a part-of-the-row cell are not split`,
                    lines.word === 1 && lines.badge === 1, `word on ${lines.word} line(s), badge on ${lines.badge} line(s)`);
                check(`row rule @${width}: a button's one-word label ("Ignore") beside a note box in a full-row cell is not split (#833 round 2)`,
                    lines.button === 1, `the label is on ${lines.button} line(s)`);
                check(`alert rule @${width}: an alert quoting a long setting name fits the screen (#833 round 2)`,
                    alertRight <= width + 1, `the alert reaches ${alertRight}px on a ${width}px screen`);
                const clear = await evaluate(ALERT_CLEAR('fx-alert'));
                check(`alert rule @${width}: the alert's words stay clear of its close button (#852)`,
                    clear !== null && clear.textRight <= clear.closeLeft, JSON.stringify(clear));
            }
        }

        // 📐 #852 item 4: /attendance's long service-type badge stays inside its
        //    own column above the phone widths, where it used to run over the
        //    next two (the phone rule never reached it).
        const attendance = WIDE_PAGES.find((w) => w.page === '/attendance');
        if (attendance !== undefined) {
            for (const width of [768, 1024]) {
                await load('/attendance', width);
                const b = await evaluate(BADGE_IN_CELL(attendance.premise));
                check(`/attendance @${width}: the service-type badge stays inside its own column (#852)`,
                    b !== null && b.badgeRight <= b.cellRight + 1, JSON.stringify(b));
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
