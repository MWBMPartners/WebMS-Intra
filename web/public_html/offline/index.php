<?php
// Path: public_html/offline/index.php
/**
 * -----------------------------------------------------------------------------
 * Offline Fallback Page 📵
 * -----------------------------------------------------------------------------
 * Displayed by the service worker when the user is offline and the requested
 * page is not cached. Uses a self-contained layout (no DB-dependent templates)
 * to ensure it works without network access.
 *
 * It tries again by itself once a minute (#865): see the script at the
 * bottom of the page for why, and for what it cannot do.
 *
 * WHY ITS ONE ADDRESS STAYS BARE (#536). Everywhere else a link is built by
 * Site::url(), which adds the organisation's part of the address where
 * organisations are told apart that way. This page cannot do that, and must
 * not:
 *   - on Apache the web server answers "/offline/" itself, as a real file,
 *     with none of the portal loaded, so there is no Site::url() to ask.
 *     (A server that hands every request to the portal has the router
 *     include this same file. It has to work both ways, so it may not lean
 *     on the portal, and it is kept self-contained on purpose: see above.)
 *   - the service worker keeps ONE copy of this page and shows it to
 *     everybody, whichever organisation they were in. An organisation's
 *     part written into it would be whoever fetched it first;
 *   - the installable-app description is one file for the whole
 *     installation, kept under this exact name by sw.js and by the shared
 *     page header.
 * So the link below carries the check's marker and its reason. The marker
 * sits INSIDE the tag, before the closing ">", on purpose: PHP takes away a
 * line break that comes straight after "?>", so at the end of the line it
 * would have changed what this page sends.
 *
 * @package   Portal
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2025-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.8.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline — Portal</title>
    <link rel="manifest" href="/manifest.json"<?php // address-check: allow — one stored copy of this page is shown offline to every organisation, and the web server answers it with none of the portal loaded; the installable-app description is one file for the whole installation (#536) ?>>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: #f8f9fa;
            color: #212529;
            text-align: center;
            padding: 2rem;
        }
        .offline-card {
            max-width: 420px;
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 2.5rem 2rem;
        }
        .offline-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        h1 { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; }
        p { color: #6c757d; margin-bottom: 1.5rem; font-size: 0.95rem; }
        .retry-btn {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
        }
        .retry-btn:hover { background: #0b5ed7; }
        .retry-note { margin: 1rem 0 0; font-size: 0.85rem; }
    </style>
</head>
<body>
<div class="offline-card">
    <div class="offline-icon">&#128268;</div>
    <h1>You're Offline</h1>
    <p>
        It looks like you've lost your internet connection.
        Please check your connection and try again.
    </p>
    <button type="button" class="retry-btn" id="offline-retry">
        Try Again
    </button>
    <p class="retry-note" id="offline-retry-note">
        This page also tries again by itself every minute.
    </p>
</div>
<script>
/*
 * 🔁 TRYING AGAIN BY ITSELF (#865), and the dark theme.
 *
 * WHY IT RETRIES WITHOUT BEING TOLD. This page is what the service worker
 * (sw.js) shows when a page cannot be fetched and no copy may be kept. A
 * person can press "Try Again"; a screen nobody touches cannot. The case
 * that found this is the noticeboard's foyer screen: its browser restarted
 * during an outage, landed here, and stayed here until somebody walked over
 * and pressed the button -- after the portal had long since come back.
 * (Before #493 P2b the worker could show a stale copy of the foyer page
 * instead, which retried by itself; that copy was removed on purpose, because
 * it went on showing posters after the screen's link had been replaced.)
 *
 * HOW: reload THIS address once a minute. The worker shows this page AS THE
 * ANSWER to the page that failed, so the address in the bar is still that
 * page's ("/noticeboard/foyer/…", say), and reloading asks for it again. If
 * the portal is still unreachable the worker answers with this page again and
 * the wait starts over; if it is back, the real page appears. Coming back
 * on line ("online" below) tries at once rather than waiting out the minute.
 *
 * TRIED AND REJECTED: waiting for the browser's "online" event alone. It
 * says whether the DEVICE has a network, not whether the portal answers, so
 * a portal that was down while the building's network stayed up would never
 * fire it. It is kept as a way to try SOONER, never as the only way.
 * TRIED AND REJECTED: asking a small address first and reloading only when it
 * answers. Every same-site request from this page goes through the same
 * worker, which answers a failed page fetch with THIS page and a 200, so a
 * probe cannot tell "back" from "still down" without a second rule about
 * which addresses the worker leaves alone -- a second copy of sw.js's
 * routing. A reload asks exactly the question that matters.
 *
 * WHAT IT CANNOT DO:
 *   - Bring a screen back when the browser reached this page WITHOUT the
 *     worker (somebody typed /offline/ while on line, or the worker is gone):
 *     a reload while the network is down then gets the browser's own error
 *     page, which runs none of this. Only the worker's fallback keeps
 *     retrying.
 *   - Retry exactly once a minute in a background tab: browsers slow timers
 *     there. A screen showing this page is in the foreground.
 *
 * THE DARK THEME used to be applied by a script in <head>, which ran before
 * <body> existed, so `document.body.style` threw for everybody who had chosen
 * the dark theme and the page stayed light. It is here now, after the
 * elements it styles, and reading the stored choice cannot throw (a browser
 * with stored data blocked refuses localStorage outright).
 */
(function () {
    var theme = null;
    try { theme = window.localStorage.getItem('portal-theme'); } catch (e) { theme = null; }
    if (theme === 'dark') {
        document.documentElement.setAttribute('data-bs-theme', 'dark');
        document.body.style.background = '#1a1d21';
        document.body.style.color = '#e9ecef';
        var card = document.querySelector('.offline-card');
        if (card !== null) { card.style.background = '#212529'; }
    }

    // One minute: often enough that a screen is back soon after the portal
    // is, and rare enough that a portal coming back is not met by every
    // screen in the building reloading every few seconds.
    var RETRY_SECONDS = 60;
    var retrying = false;
    var tryAgain = function () {
        if (retrying === true) { return; }
        retrying = true;
        window.location.reload();
    };

    var button = document.getElementById('offline-retry');
    if (button !== null) { button.addEventListener('click', tryAgain); }
    window.addEventListener('online', tryAgain);
    window.setTimeout(tryAgain, RETRY_SECONDS * 1000);
})();
</script>
</body>
</html>
