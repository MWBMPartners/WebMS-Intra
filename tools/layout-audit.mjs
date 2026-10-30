/**
 * -----------------------------------------------------------------------------
 * File:        tools/layout-audit.mjs
 * Description: Looks at real pages in a real browser at several screen widths
 *              and reports what a person would see wrong: content wider than
 *              the screen, controls too small to tap, a missing title or
 *              description, pictures with no alternative text or that failed
 *              to load, stylesheets that did not apply, a page that shows
 *              nothing, and the JavaScript errors and failed requests the
 *              browser itself reported. With --keep-screenshots it writes a
 *              screenshot per page per width so a human can check what no
 *              machine can judge.
 * Package:     Portal\Tools
 * Author:      MWBM Partners Ltd (t/a MWservices)
 * Copyright:   2026-present MWBM Partners Ltd (t/a MWservices) - All Rights Reserved
 * Version:     1.5.0
 * Link:        https://github.com/MWBMPartners/WebMS-Intra/issues/569
 * -----------------------------------------------------------------------------
 *
 * WHY THIS EXISTS
 *   The owner's rule is that nothing may look unfinished, careless or AI-made,
 *   and that the browser half of that check runs as a full audit before every
 *   release. Until 30 October 2026 it was reported as blocked for want of a
 *   headless browser. That was wrong: Microsoft Edge is installed, and Edge is
 *   Chromium.
 *
 * WHAT IT NEEDS, AND WHY NOTHING IS INSTALLED
 *   Microsoft Edge, and node 22 or newer. Nothing else -- no Playwright, no
 *   Puppeteer, no `npm install`. Edge is driven over the DevTools protocol
 *   using node's own built-in `WebSocket`, which has been a global since
 *   node 22. That matters on this project: a check nobody can run before a
 *   push is the #646 failure, and asking the owner to install a browser
 *   toolchain is exactly the kind of thing that leaves a check unrun.
 *
 * THE TRAP THAT MAKES THE OBVIOUS OVERFLOW TEST USELESS
 *   With `mobile: true`, Chromium WIDENS THE LAYOUT VIEWPORT to fit the
 *   content. A 900px-wide page asked to render at 390 reports
 *   `window.innerWidth` of 900. So this:
 *
 *       document.documentElement.scrollWidth > window.innerWidth
 *
 *   is PERMANENTLY FALSE, on every page, however broken -- the worst kind of
 *   check, because it is believed. Measured on a deliberately-overflowing
 *   fixture and a clean one before this file was written.
 *
 *   Every width comparison here is therefore against `asked`, the width this
 *   script requested, which is known from OUTSIDE the page. Nothing trusts a
 *   number the page reports about its own viewport.
 *
 * WHAT IT CANNOT DO, said plainly because an overstated check is worse than none
 *   * It cannot tell whether a page looks GOOD. Visual judgement, brand
 *     consistency and whether a layout is attractive are not measurable here;
 *     that is what the screenshots are for.
 *   * It cannot see a problem that needs a signed-in session unless it is
 *     given one. Pages behind a sign-in are in scope for the owner's
 *     checklist, so a cookie can be supplied with --cookie.
 *   * Colour contrast is NOT checked. Doing it properly needs the computed
 *     colour behind every element including images and gradients, and a
 *     half-done contrast check produces mostly false alarms.
 *   * A tap target is measured by its own box. A small link inside a large
 *     padded parent that is itself clickable will be reported even though a
 *     finger would hit it. Those are listed as warnings, not failures.
 *   * White-on-white text, a wrong colour, a crooked layout -- anything that
 *     needs an eye. The screenshots are for that.
 *   * Whether a page is the RIGHT page. It checks the navigation did not
 *     error, but a 404 page served with a 200 would be measured as the page.
 *
 * WHAT IT DOES NOT NEED, AND WHAT IT DOES
 *   Nothing is installed: Edge is driven over the DevTools protocol with
 *   node's own built-in WebSocket, so node 22 or newer is required. Neither
 *   this nor the PHP driver checks the node version -- on node 20 the first
 *   sign is `WebSocket is not defined`.
 *   Some pages load stylesheets from a content delivery network, so an audit
 *   run offline will report those as not applied. That is the network, not
 *   the page.
 */

import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { argv, exit } from 'node:process';

// 📐 The widths to look at. 320 is the narrowest phone still in use; 390 is a
// current iPhone; 768 is a tablet in portrait; 1024 and 1440 are laptops.
const WIDTHS = [320, 390, 768, 1024, 1440];

// 👆 Apple and Google both say about 44 CSS pixels is the smallest comfortable
// tap target. Anything under this is reported as a warning.
const MIN_TAP = 44;

const EDGE = '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge';

function arg(name, fallback = null) {
  const hit = argv.find(a => a.startsWith('--' + name + '='));
  return hit ? hit.slice(name.length + 3) : fallback;
}

// node 22 is where the built-in WebSocket arrived. Say so plainly rather than
// failing later with "WebSocket is not defined", which names nothing useful.
if (typeof WebSocket === 'undefined') {
  console.error('FAIL: this node has no built-in WebSocket (node 22 or newer is needed).');
  console.error('      node here is ' + process.version + '.');
  exit(2);
}

const BASE = arg('base', 'http://127.0.0.1:9090');
const OUT = arg('out', './layout-audit');
const COOKIE = arg('cookie');
const PAGES = (arg('pages') || '/').split(',').map(s => s.trim()).filter(Boolean);
// A port of this run's own. The first version used a fixed 9444, and when a
// previous run leaked its browser the next run's `/json/version` fetch SUCCEEDED
// against the stale one -- so it measured pages in a browser it did not start,
// with whatever state that browser was in, and reported the results as its own.
// Two audits also could not run side by side, against the project's rule about
// separate ports per package.
const PORT = Number(arg('port', String(9400 + (process.pid % 150))));
const KEEP = argv.includes('--keep-screenshots');

/** Start Edge headless with remote debugging, and wait until it answers. */
async function startEdge(profileDir) {
  const child = spawn(EDGE, [
    '--headless', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
    '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + profileDir,
    'about:blank',
  ], { stdio: 'ignore', detached: false });

  for (let i = 0; i < 60; i++) {
    // ⚠️ Only accept the browser WE started. Without the exit check a leftover
    //    browser on this port answers, and the run quietly uses it.
    if (child.exitCode !== null) {
      throw new Error('Edge exited immediately (code ' + child.exitCode + ').');
    }
    try {
      const r = await fetch('http://127.0.0.1:' + PORT + '/json/version');
      if (r.ok) { return { child, version: (await r.json()).Browser }; }
    } catch { /* not up yet */ }
    await new Promise(r => setTimeout(r, 500));
  }
  throw new Error('Edge did not answer on port ' + PORT + ' within 30 seconds.');
}

/** A tiny DevTools client. One socket, one promise per message id. */
async function connect(wsUrl) {
  const ws = new WebSocket(wsUrl);
  let id = 0;
  const waiting = new Map();
  const events = [];
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); }
    else if (m.method) { events.push(m); }
  };
  const send = (method, params = {}, sessionId) => new Promise(res => {
    const n = ++id;
    waiting.set(n, res);
    ws.send(JSON.stringify({ id: n, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  return { ws, send, events };
}

// 🔍 This runs INSIDE the page. `asked` is passed in from outside: see the
// header note about why the page's own innerWidth must never be trusted here.
const MEASURE = asked => `(() => {
  const doc = document.documentElement;
  const tooWide = [];
  // Only report an element that is itself wider than the screen or starts off
  // the right-hand edge. Walking every node would list a wide element's whole
  // ancestry and bury the one that matters.
  for (const el of document.body.querySelectorAll('*')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    if (r.right > ${asked} + 1 || r.width > ${asked} + 1) {
      const tag = el.tagName.toLowerCase();
      const id = el.id ? '#' + el.id : '';
      const cls = (el.className && typeof el.className === 'string')
        ? '.' + el.className.trim().split(/\\s+/).slice(0, 2).join('.') : '';
      tooWide.push({ sel: tag + id + cls, width: Math.round(r.width), right: Math.round(r.right) });
    }
  }
  const small = [];
  for (const el of document.querySelectorAll('a, button, input, select, textarea, [role=button]')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;          // hidden: not a target
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none') continue;
    if (r.width < ${MIN_TAP} || r.height < ${MIN_TAP}) {
      small.push({ sel: el.tagName.toLowerCase(), w: Math.round(r.width), h: Math.round(r.height),
                   text: (el.textContent || '').trim().slice(0, 30) });
    }
  }
  const imgs = [...document.querySelectorAll('img')]
    .filter(i => i.getAttribute('alt') === null)
    .map(i => ({ src: (i.getAttribute('src') || '').slice(-60) }));
  // A picture that did not LOAD: complete, with a zero naturalWidth, is the
  // browser saying it tried and failed. Having an alt attribute is not the
  // same as working -- the sign-in page's logo carried one and still rendered
  // as a broken-image icon, and nothing above would have noticed.
  // (No backticks in here: this whole block is a template literal.)
  const broken = [...document.querySelectorAll('img')]
    .filter(i => i.complete === true && i.naturalWidth === 0)
    .map(i => ({ src: (i.getAttribute('src') || '(no src)').slice(-70) }));
  // How many stylesheets the browser ACTUALLY applied. A page whose CSS did
  // not load still renders, still has a title, and still reports no overflow
  // -- so without this the whole audit can pass on a page nobody styled, which
  // is the "check that cannot fail" shape. Served with the wrong Content-Type
  // a stylesheet is REFUSED, and that really happened here: the test router
  // sent text/html for everything until it was fixed.
  let sheetsApplied = 0, inlineStyles = 0;
  // Count DISTINCT hrefs, not raw <link> elements. A page that links the same
  // stylesheet twice has one sheet in document.styleSheets, because the
  // browser dedupes -- so comparing against the raw count reported "applied
  // only 5 of 6" on a page where nothing had failed at all. /help really does
  // link print.css twice, which is worth knowing, but it is a tidiness matter
  // and NOT a styling failure.
  const linkEls = [...document.querySelectorAll('link[rel~=stylesheet]')];
  // ⚠️ A DISABLED link can never appear in document.styleSheets, so counting
  //    it as "linked" hard-fails a page where nothing is wrong. header.php
  //    links print.css twice -- once media="print", once media="screen"
  //    disabled -- so deleting the enabled one would have made EVERY page in
  //    the portal fail this check. The duplicate warning below already
  //    excluded disabled links; the two disagreed about the same elements.
  const liveLinks = linkEls.filter(l => l.disabled !== true);
  const hrefs = liveLinks.map(l => l.href);
  const sheetsLinked = new Set(hrefs).size;
  // A duplicate is only pointless when the two links are for the SAME media
  // and neither is disabled. Linking one file twice with different media, or
  // with one disabled, is a real pattern -- header.php does exactly that with
  // print.css (one media="print", one media="screen" disabled, for a print
  // preview). Reporting that was a false alarm, and a warning that cries wolf
  // is as bad as one that catches nothing.
  const liveKeys = linkEls
    .filter(l => l.disabled !== true)
    .map(l => l.href + '|' + (l.media || 'all'));
  const duplicateLinks = linkEls
    .filter(l => l.disabled !== true)
    .filter((l, i) => liveKeys.indexOf(l.href + '|' + (l.media || 'all')) !== i)
    .map(l => l.href);
  inlineStyles = document.querySelectorAll('style').length;
  for (const sheet of document.styleSheets) {
    // A sheet that LOADED counts, even with no rules in it: an empty
    // stylesheet is valid, and requiring rules reported a page with one as
    // "not measured in the state a visitor sees". Being in document.styleSheets
    // at all is the browser saying it accepted the file.
    try { if (sheet.cssRules !== null) sheetsApplied++; }
    catch (e) { sheetsApplied++; }   // cross-origin: it loaded, we cannot read it
  }
  const desc = document.querySelector('meta[name="description"]');
  return {
    scrollWidth: doc.scrollWidth,
    overflows: doc.scrollWidth > ${asked} + 1,
    reportedInnerWidth: window.innerWidth,   // kept only to SHOW the trap, never compared
    tooWide: tooWide.slice(0, 8),
    tooWideCount: tooWide.length,
    small: small.slice(0, 8),
    smallCount: small.length,
    imgsNoAlt: imgs.slice(0, 8),
    imgsNoAltCount: imgs.length,
    broken: broken.slice(0, 8),
    brokenCount: broken.length,
    sheetsApplied, sheetsLinked, inlineStyles,
    duplicateLinks: [...new Set(duplicateLinks)].map(h => h.split('/').pop()),
    title: document.title || '',
    description: desc ? (desc.getAttribute('content') || '') : null,
    hasH1: document.querySelector('h1') !== null,
    // Did the page actually SHOW anything? A page that dies after header.php
    // has printed the head has a perfect title, perfect meta tags, no overflow
    // and an empty body -- and passed every check. innerText is what is
    // VISIBLE, so a body hidden behind display:none reads as empty too, which
    // an element count would not catch.
    // (No backticks in this block: it is inside a template literal.)
    visibleText: (document.body ? (document.body.innerText || '').trim().length : 0),
    bodyHeight: (document.body ? document.body.getBoundingClientRect().height : 0),
  };
})()`;

async function main() {
  const profile = OUT + '/.edge-profile';
  mkdirSync(OUT, { recursive: true });
  mkdirSync(profile, { recursive: true });

  LIVE_PROFILE = profile;
  const { child, version } = await startEdge(profile);
  LIVE_CHILD = child;
  const ver = await (await fetch('http://127.0.0.1:' + PORT + '/json/version')).json();
  const { ws, send, events } = await connect(ver.webSocketDebuggerUrl);

  const { targetId } = (await send('Target.createTarget', { url: 'about:blank' })).result;
  const { sessionId } = (await send('Target.attachToTarget', { targetId, flatten: true })).result;
  await send('Page.enable', {}, sessionId);
  await send('Runtime.enable', {}, sessionId);
  await send('Log.enable', {}, sessionId);
  await send('Network.enable', {}, sessionId);
  if (COOKIE) {
    const [name, ...rest] = COOKIE.split('=');
    const u = new URL(BASE);
    await send('Network.enable', {}, sessionId);
    await send('Network.setCookie',
      { name, value: rest.join('='), domain: u.hostname, path: '/' }, sessionId);
  }

  const problems = [];
  const warnings = [];
  const rows = [];

  for (const page of PAGES) {
    for (const asked of WIDTHS) {
      await send('Emulation.setDeviceMetricsOverride',
        { width: asked, height: 900, deviceScaleFactor: 1, mobile: asked < 768 }, sessionId);

      const url = BASE + page;
      events.length = 0;                       // only this page's complaints

      // ❗ CHECK THE NAVIGATION ACTUALLY WORKED. Page.navigate returns an
      //    errorText when it did not, and ignoring it is how an earlier
      //    version measured CHROMIUM'S OWN NETWORK-ERROR PAGE and reported it
      //    as the page: pointed at a port with nothing listening it printed
      //    "Measurements: 5" and one tidy finding. An error page has a title,
      //    a body and no overflow, so every check below passes on it.
      const nav = await send('Page.navigate', { url }, sessionId);
      const navErr = nav.result && nav.result.errorText;
      if (navErr) {
        problems.push(`${page} @${asked}: the page did not load — ${navErr}`);
        continue;
      }

      // Wait for the load event rather than a fixed delay, so a stylesheet
      // that arrives late is not counted as one that never arrived. A fixed
      // 900ms blamed the PAGE for a slow network, and /login links Bootstrap
      // and Font Awesome from two content delivery networks.
      await Promise.race([
        new Promise(res => {
          const started = Date.now();
          const poll = setInterval(() => {
            const done = events.some(e => e.method === 'Page.loadEventFired');
            if (done || Date.now() - started > 15000) { clearInterval(poll); res(); }
          }, 100);
        }),
        new Promise(res => setTimeout(res, 15000)),
      ]);
      await new Promise(r => setTimeout(r, 250));   // let late styles settle

      // The browser's own complaints about THIS page.
      const failedReqs = events
        .filter(e => e.method === 'Network.loadingFailed')
        .filter(e => !(e.params && e.params.canceled))
        .length;
      // Keep the TEXT, not just a count: "1 JavaScript error" tells nobody
      // what to fix, and the point of this check is that the page looks
      // finished while something on it does nothing.
      const errorTexts = events.flatMap(e => {
        if (e.method === 'Log.entryAdded' && e.params && e.params.entry
            && e.params.entry.level === 'error') {
          return [(e.params.entry.text || '').slice(0, 160)];
        }
        if (e.method === 'Runtime.exceptionThrown' && e.params
            && e.params.exceptionDetails) {
          const d = e.params.exceptionDetails;
          const msg = (d.exception && d.exception.description) || d.text || 'an exception';
          return [String(msg).split('\n')[0].slice(0, 160)];
        }
        return [];
      });
      const consoleErrors = errorTexts.length;

      const ev = await send('Runtime.evaluate',
        { expression: MEASURE(asked), returnByValue: true, awaitPromise: false }, sessionId);
      if (!ev.result || !ev.result.result || ev.result.result.value === undefined) {
        problems.push(`${page} @${asked}: the page could not be measured at all`);
        continue;
      }
      const m = ev.result.result.value;
      rows.push({ page, asked, ...m });

      // ❗ Content wider than the screen. Compared against `asked`, never
      //    against m.reportedInnerWidth -- see the header.
      if (m.overflows === true) {
        problems.push(`${page} @${asked}: content is ${m.scrollWidth}px wide on a ${asked}px screen`
          + (m.tooWide.length ? ` — widest: ${m.tooWide.map(t => t.sel + ' (' + t.width + 'px)').slice(0, 3).join(', ')}` : ''));
      }
      // ❗ A page whose JavaScript died still renders, still has a title and
      //    still has its stylesheets -- so without this it passes. In this
      //    portal that is the #625 shape exactly: a script refused for a
      //    missing nonce logs nothing server-side and the control silently
      //    does nothing.
      if (consoleErrors > 0) {
        problems.push(`${page} @${asked}: the browser reported ${consoleErrors}`
          + ` JavaScript error(s) — the page may look finished and do nothing`
          + `\n      ${[...new Set(errorTexts)].slice(0, 3).join('\n      ')}`);
      }
      if (failedReqs > 0) {
        problems.push(`${page} @${asked}: ${failedReqs} request(s) failed to load`);
      }

      if (asked === WIDTHS[0]) {
        // ❗ A page that links stylesheets but applied none was NOT MEASURED in
        //    the state a visitor sees. Report it as a problem, not a warning:
        //    every other finding on that page is worthless.
        // ⚠️ ANY shortfall, not just zero. The first version asked for
        //    `sheetsApplied === 0`, and the real failure was PARTIAL: one of
        //    three stylesheets refused, the page still looked broadly right,
        //    and every control measured 4px shorter than the truth. A
        //    partially-styled page is not the page a visitor sees, and the
        //    numbers taken from it are wrong in a way nobody would question.
        if (m.sheetsApplied < m.sheetsLinked) {
          problems.push(`${page}: applied only ${m.sheetsApplied} of ${m.sheetsLinked}`
            + ` linked stylesheet(s) — this page was NOT measured in the state a`
            + ` visitor sees, so every other finding on it is unreliable`);
        }
        if (m.duplicateLinks.length > 0) {
          warnings.push(`${page}: links the same stylesheet more than once`
            + ` — ${m.duplicateLinks.join(', ')}`);
        }
        if (m.visibleText < 20 && m.bodyHeight < 40) {
          problems.push(`${page}: the page SHOWS NOTHING`
            + ` (${m.visibleText} characters of visible text, body ${Math.round(m.bodyHeight)}px tall)`
            + ` — this is what a page looks like when it dies after its <head> is written`);
        }
        if (m.title.trim() === '') { problems.push(`${page}: no page title`); }
        if (m.description === null) { problems.push(`${page}: no meta description`); }
        else if (m.description.trim() === '') { problems.push(`${page}: meta description is empty`); }
        if (m.hasH1 === false) { warnings.push(`${page}: no <h1> heading`); }
        if (m.imgsNoAltCount > 0) {
          problems.push(`${page}: ${m.imgsNoAltCount} picture(s) with no alt attribute`);
        }
        if (m.brokenCount > 0) {
          problems.push(`${page}: ${m.brokenCount} picture(s) did NOT LOAD`
            + ` — ${m.broken.map(b => b.src).slice(0, 3).join(', ')}`);
        }
      }
      if (m.smallCount > 0 && asked < 768) {
        warnings.push(`${page} @${asked}: ${m.smallCount} control(s) under ${MIN_TAP}px`
          + ` — e.g. ${m.small.slice(0, 2).map(s => s.sel + ' ' + s.w + '×' + s.h).join(', ')}`);
      }

      const shot = await send('Page.captureScreenshot', { format: 'png' }, sessionId);
      if (shot.result && shot.result.data && KEEP) {
        const safe = page.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'root';
        writeFileSync(`${OUT}/${safe}@${asked}.png`, Buffer.from(shot.result.data, 'base64'));
      }
    }
  }

  ws.close();
  closeBrowser();

  console.log(`Browser: ${version}`);
  console.log(`Pages: ${PAGES.length}   Widths: ${WIDTHS.join(', ')}   Measurements: ${rows.length}`);
  const sheets = rows.length ? rows[0] : null;
  if (sheets) {
    console.log(`Stylesheets on the first page: ${sheets.sheetsApplied} applied`
      + ` of ${sheets.sheetsLinked} linked, plus ${sheets.inlineStyles} inline`
      + ` — if "applied" is 0 while "linked" is not, nothing below means anything.`);
  }
  // Showing the trap in the output, so nobody re-derives the wrong comparison.
  const widened = rows.filter(r => r.reportedInnerWidth !== r.asked);
  if (widened.length) {
    console.log(`Note: on ${widened.length} measurement(s) the page reported an innerWidth`
      + ` different from the width asked for (e.g. asked ${widened[0].asked},`
      + ` page said ${widened[0].reportedInnerWidth}). That is why every width here is`
      + ` compared against the width asked for.`);
  }
  console.log('');
  if (problems.length === 0) { console.log('✓ no layout problems found'); }
  else { console.log(`✗ ${problems.length} problem(s):`); for (const p of problems) console.log('   ' + p); }
  if (warnings.length) {
    console.log(`\n${warnings.length} warning(s) (a human should look):`);
    for (const w of warnings) console.log('   ' + w);
  }
  if (rows.length === 0) {
    console.log('\nFAIL: nothing was measured, so this run proved nothing.');
    exit(2);
  }
  exit(problems.length === 0 ? 0 : 1);
}

// ⚠️ THE BROWSER MUST ALWAYS DIE. The first version killed it only on the
//    happy path, so any throw -- and Ctrl-C -- left a headless Edge running.
//    Measured during the independent check: ten processes after one failure,
//    and because the port was fixed the NEXT run attached to the leftover and
//    reported results from a browser it had not started.
let LIVE_CHILD = null;
let LIVE_PROFILE = null;

function closeBrowser() {
  if (LIVE_CHILD !== null) {
    try { LIVE_CHILD.kill('SIGKILL'); } catch { /* already gone */ }
    LIVE_CHILD = null;
  }
  if (LIVE_PROFILE !== null) {
    // A moment for Edge to let go of its profile, or rmSync races it and
    // leaves .edge-profile behind in the output folder.
    try { rmSync(LIVE_PROFILE, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); }
    catch { /* fine */ }
    LIVE_PROFILE = null;
  }
}

for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
  process.on(sig, () => { closeBrowser(); process.exit(130); });
}
process.on('exit', closeBrowser);
process.on('uncaughtException', e => {
  console.error('FAIL: ' + (e && e.message ? e.message : e));
  closeBrowser();
  process.exit(2);
});

// An overall deadline, so a page that never answers cannot hang the run for
// ever with a browser alive. The project's own rule is to put a watchdog
// beside anything you wait on.
const DEADLINE_MS = Number(arg('deadline', '600000'));
const deadline = setTimeout(() => {
  console.error(`FAIL: the audit passed its ${Math.round(DEADLINE_MS / 1000)}-second deadline.`);
  closeBrowser();
  process.exit(2);
}, DEADLINE_MS);
deadline.unref();

main().catch(e => {
  console.error('FAIL: ' + (e && e.message ? e.message : e));
  closeBrowser();
  exit(2);
});
