<?php
// Path: _install/csp.php
/**
 * -----------------------------------------------------------------------------
 * Installer — the browser's rule for what its pages may run and reach 🛡️ (#942)
 * -----------------------------------------------------------------------------
 * The installer sends a strict Content Security Policy (a "CSP": a header that
 * tells the browser what a page may run and which addresses it may reach) with
 * EVERY answer, including its plain error pages and its redirects. Until #942
 * it sent none.
 *
 * WHY, AND WHY HERE. D-133 promises that the installer's country suggestion
 * "sends nothing anywhere": it reads the installing person's own browser time
 * zone, in the browser. Four check rounds tried to PROVE that by reading the
 * page's source or watching a browser, and each was got past (a WebSocket, a
 * timer, an `innerHTML` picture, a `pagehide` beacon, a script tag in capitals,
 * an `onload=` attribute, an `<iframe srcdoc>`, a `javascript:` address, a tag
 * built in PHP from two halves). So the browser is given a rule to enforce.
 *
 * WHAT THE POLICY BLOCKS (each item measured in Edge headless during #942's checks):
 *   a script without this answer's nonce (in any case or spelling, built in PHP from pieces, or inside an
 *   `<iframe srcdoc>`), inline event handlers (`onload=` and the like), `javascript:` addresses, fetch,
 *   XMLHttpRequest, WebSockets, EventSource, beacons (on `pagehide` too), `<a ping>`, `<link rel=prefetch>`,
 *   a form posting to another host, and pictures, frames, fonts, media and stylesheets from other hosts
 *   (except the jsDelivr stylesheet below).
 * WHAT IT DOES NOT BLOCK (also measured, and NOT a complete list — only what #942's checks tried):
 *   navigating the page elsewhere (`location`, a `<meta http-equiv=refresh>` a script adds); `window.open`
 *   (it reached a listener once the popup blocker was off); a stylesheet from `cdn.jsdelivr.net` with anything
 *   after the host name (a policy source matches every path under it, #976); `<link rel=prerender>` and
 *   speculation rules (a nonce-carrying `<script type=speculationrules>` that prefetches or prerenders any
 *   address); WebRTC (an `RTCPeerConnection` to a TURN server); requests to the installer's OWN server (a
 *   picture at `/x?z=…` loads); preconnect hints; and DNS-prefetch hints (NOT measured: a DNS lookup is not a request a test can record). A browser may have other routes nobody
 *   has tried.
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
 * The installer has no autoloader and no `App`, so this lives here, in the
 * installer, and `App::cspNonce()` is not used. Its FOUR inline scripts each
 * carry `installCspNonce()`.
 *
 * WHAT THE PAGES NEED, measured: one stylesheet from the jsDelivr CDN (Bootstrap,
 * with an integrity hash), inline `<style>` blocks and `style=` attributes (so
 * `'unsafe-inline'` for styles; a style runs no code, but it may load a
 * stylesheet from cdn.jsdelivr.net, which is the route listed above), pictures
 * in the CSS written as `data:` addresses, and favicons from the installer's own
 * server.
 *
 * @package   Portal\Install
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/942
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * 🎲 THIS answer's nonce: 32 hexadecimal characters, made once per request.
 */
function installCspNonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = bin2hex(random_bytes(16));
    }

    return $nonce;
}

/**
 * 📜 The policy for a given nonce. Separate from the sending so the self-tests
 * can serve the SAME policy to a fixture page (tools/installer-country-script-selftest.mjs).
 */
function installCspPolicy(string $nonce): string
{
    return implode('; ', [
        "default-src 'none'",
        "script-src 'nonce-" . $nonce . "'",
        "connect-src 'none'",
        "img-src 'self' data:",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        "font-src 'self'",
        "frame-src 'none'",
        "worker-src 'none'",
        "object-src 'none'",
        "manifest-src 'self'",
        "media-src 'none'",
        "form-action 'self'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
    ]);
}

/**
 * 🛡️ Send the policy with this answer. Called once, at the very top of the
 * installer, before anything can be written, so every answer carries it: the
 * wizard's pages, its plain error pages and its redirects alike.
 */
function installSendCsp(): void
{
    if (headers_sent() === true) {
        return;
    }
    header('Content-Security-Policy: ' . installCspPolicy(installCspNonce()));
}
