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
 * page's source or watching a browser, and each was got past: a WebSocket, a
 * timer, an `innerHTML` picture, a `pagehide` beacon, a script tag in capitals,
 * an `onload=` attribute, an `<iframe srcdoc>`, a `javascript:` address, a tag
 * built in PHP from two halves. Reading source cannot list every way HTML runs
 * code. So the BROWSER enforces it: with this policy
 *
 *   - only a `<script>` carrying THIS answer's nonce (a random value made fresh
 *     for every answer) runs. Every other script, in any case or spelling,
 *     every `onload=`/`onclick=` attribute, every `javascript:` address and
 *     every script inside an `<iframe srcdoc>` is refused;
 *   - nothing may be fetched, beaconed, streamed or opened as a WebSocket
 *     (`connect-src 'none'`), no frame may load (`frame-src 'none'`), no form may
 *     post anywhere but the installer itself (`form-action 'self'`), and a
 *     picture may come only from the installer's own server or be written into
 *     the page (`img-src 'self' data:`).
 *
 * The installer has no autoloader and no `App`, so this lives here, in the
 * installer, and `App::cspNonce()` is not used. The installer's FOUR inline
 * scripts each carry `installCspNonce()`; an extra script would run only if it
 * were written into our own code WITH that nonce, which is a change a reviewer
 * reads.
 *
 * WHAT IT DOES NOT BLOCK, said plainly:
 *   - DNS-prefetch and preconnect HINTS (`<link rel=dns-prefetch>` /
 *     `preconnect`). Browsers do not put them under any directive, so a host
 *     name in such a hint is looked up whatever this says. The suggestion
 *     script is therefore ALSO pinned as text
 *     (tools/audit-checks/check_installer_country_script_pinned.py): a hint built
 *     from the time zone has to be written somewhere in our own code.
 *   - Navigating the whole page to another address (a link, a redirect). Not
 *     restricted by this policy; the pin is the guard for the suggestion
 *     script.
 *   - Requests to the installer's OWN server: pictures from 'self' are allowed.
 *
 * WHAT THE PAGES NEED, measured: one stylesheet from the jsDelivr CDN (Bootstrap,
 * with an integrity hash), inline `<style>` blocks and `style=` attributes (so
 * `'unsafe-inline'` for styles — a style cannot run code or reach another host
 * under `img-src`/`font-src`), pictures in the CSS written as `data:` addresses,
 * and favicons from the installer's own server.
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
