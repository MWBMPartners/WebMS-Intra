<?php
// Path: _core/SafeFetch.php
/**
 * -----------------------------------------------------------------------------
 * Fetching an outside web address safely 🌐🛡️ (#514, part P4)
 * -----------------------------------------------------------------------------
 * The portal is about to fetch web addresses that an administrator typed in:
 * outside calendars (Google, Microsoft 365 and others, from part P6 of #514
 * onwards). Any feature that fetches an address a person supplied can be
 * turned against the server it runs on. Somebody types an address that
 * points at the hosting company's own internal network (a database, an
 * admin panel, the "metadata" address cloud servers use to hand out their
 * own passwords), and the portal fetches it for them from the inside. That
 * is called "request forgery", and this class exists to stop it.
 *
 * WHAT IT DOES
 * ------------
 * - `normaliseUrl()` tidies an address into one standard spelling, or
 *   refuses it. Only http and https are accepted; `webcal://` and
 *   `webcals://` (how calendar apps write a calendar address) become
 *   https (owner decision D17). No user name or password inside the
 *   address, no unusual port, no control characters.
 * - `isPublicIp()` says whether a numeric internet address is an ordinary
 *   public one. Everything private, internal, reserved or special is
 *   refused.
 * - `check()` refuses internal-looking names outright, looks the name up,
 *   and refuses it unless EVERY address it points at is public.
 * - `get()` downloads the address with every guard in place: it connects
 *   ONLY to the addresses `check()` approved (so the name cannot be
 *   quietly re-pointed between the check and the download, a trick called
 *   "DNS rebinding"), follows redirects one at a time by hand and checks
 *   every new address the same way, ignores any proxy configured on the
 *   server, caps the size and the time taken, and never writes the address
 *   into any log.
 *
 * WHY ONE MESSAGE FOR EVERY REFUSED ADDRESS
 * -----------------------------------------
 * `REFUSED_MESSAGE` is used whether the address points at a private network
 * or its name simply does not exist (#514 leak-hunt finding 29). Two
 * different messages would let somebody map the host's internal network by
 * trying names and watching which message comes back.
 *
 * WHY THE ADDRESS IS NEVER LOGGED
 * -------------------------------
 * A Google "secret address in iCal format" IS the password to that calendar:
 * anybody holding it can read every event. So a log line from this class
 * names the host (for example `calendar.google.com`) and the HTTP status,
 * and nothing else. The messages handed back to callers never contain the
 * address either.
 *
 * WHAT THIS CLASS CANNOT DO
 * -------------------------
 * - It cannot bound the time the name lookup takes. PHP's
 *   `dns_get_record()` has no time limit of its own, so a very slow name
 *   server can make `check()` take longer than the `deadline` given to
 *   `get()`. The download itself always respects the deadline.
 * - The extra "pre-request" check (the connection is stopped unless it
 *   went to an approved address) needs libcurl 7.80 or later, and PHP 8.4
 *   or later (the oldest PHP this portal supports). On an older libcurl
 *   that check is simply missing; the pinning to approved addresses still
 *   applies.
 * - A host that only lets web requests out through a proxy will fail every
 *   fetch, because this class deliberately switches proxies off (a proxy
 *   would do its own name lookup, out of our sight). The failure message
 *   is plain.
 * - It does not decide WHICH addresses a feature should accept beyond
 *   "public, http or https". Features with a tighter rule (the hymn lookup
 *   accepts one configured host only) add that rule themselves.
 * - It knows nothing about calendars. The calendar reader is part P5 of
 *   #514; this class only moves bytes.
 *
 * WHO USES IT
 * -----------
 * - The outside-calendar importer (#514 part P6): `normaliseUrl()` and
 *   `check()` when an address is saved, `get()` for every refresh.
 * - `Hymnal` (the hymn lookup, #128) uses `isPublicIp()` for its own address
 *   test, so both features refuse exactly the same kinds of address. The
 *   hymn lookup's download is not pinned yet; that is a follow-up issue
 *   raised by part P11 of #514.
 * - Outbound webhooks and Web Push do NOT use it yet (a follow-up issue,
 *   also from part P11).
 *
 * @package   Portal\Core
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Portal\Core;

final class SafeFetch
{
    // =========================================================================
    // 💬 Messages — plain English, and never containing the address
    // =========================================================================

    /**
     * The one answer for every refused address (#514 leak-hunt finding 29).
     * Callers show it as it is; the importer's add form flashes it.
     */
    public const REFUSED_MESSAGE = 'This address cannot be used: it points at a private or internal network, or its name could not be found.';

    private const MSG_HTTPS_ONLY  = 'Only secure (https) addresses can be used here.';
    private const MSG_DOWNGRADE   = 'The address redirected from a secure (https) address to an insecure (http) one, which is not allowed.';
    private const MSG_TOO_MANY    = 'The address redirected too many times.';
    private const MSG_TOO_SLOW    = 'The other server took too long to answer.';
    private const MSG_TOO_BIG     = 'The file is bigger than the size limit, so it was not read.';
    private const MSG_UNREACHABLE = 'The other server could not be reached.';
    private const MSG_STATUS      = 'The other server answered with an error (HTTP status %d).';
    private const MSG_NO_CURL     = 'This server cannot fetch outside addresses: the PHP curl extension is not installed.';

    // =========================================================================
    // 📏 Address rules
    // =========================================================================

    /** Longest address accepted, in bytes. Real calendar addresses are far shorter. */
    private const MAX_URL_LENGTH = 2000;

    /**
     * Accepted schemes, and what each one is fetched as. `webcal` and
     * `webcals` are how calendar apps write a calendar address; they are
     * fetched over https (owner decision D17). Anything else — ftp, file,
     * gopher, data and the rest — is refused.
     */
    private const SCHEMES = [
        'http'    => 'http',
        'https'   => 'https',
        'webcal'  => 'https',
        'webcals' => 'https',
    ];

    /**
     * The only port each scheme may use. An unusual port is refused: it is
     * the classic way to reach a service on the host that was never meant
     * to face the internet (a database on 3306, a mail server on 25).
     */
    private const DEFAULT_PORTS = [
        'http'  => 80,
        'https' => 443,
    ];

    /**
     * Names refused before they are even looked up: `localhost` and
     * anything ending `.localhost`, `.local`, `.internal` or `.home.arpa`.
     * These are reserved for machines on the same network, so no public
     * calendar can live there. (The bare words `local`, `internal` and
     * `home.arpa` are refused too.)
     */
    private const REFUSED_NAME_SUFFIXES = ['localhost', 'local', 'internal', 'home.arpa'];

    /**
     * A host name after conversion to plain letters: labels of letters,
     * digits, hyphens and underscores, separated by single dots. Anything
     * else — a percent sign, a backslash, an empty label — is refused, so
     * this class and curl can never read the same address two different
     * ways.
     */
    private const HOST_PATTERN = '/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*$/';

    /** HTTP answers that mean "fetch this other address instead". */
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    // =========================================================================
    // 🚫 Address ranges refused on top of PHP's own "global range" test
    // =========================================================================

    /**
     * IPv4 ranges this class refuses itself, on top of PHP's
     * `FILTER_FLAG_GLOBAL_RANGE`:
     *   224.0.0.0/4   — multicast. PHP 8.5.10's filter lets it THROUGH
     *                   (tested: 224.0.0.1 and 239.255.255.255 pass), so this
     *                   entry is the only thing refusing it.
     *   240.0.0.0/4   — the old "class E" block.
     *   100.64.0.0/10 — the shared space internet providers use inside their
     *                   own networks.
     * PHP 8.5.10's filter already refuses the last two (tested). They are
     * listed so that an older or newer PHP cannot quietly let them through;
     * the self-test checks every entry of this table on its own, so an entry
     * cannot go missing unnoticed just because PHP happens to cover for it.
     */
    private const IPV4_REFUSED_RANGES = [
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
        ['100.64.0.0', 10],
    ];

    /**
     * IPv6 ranges this class refuses itself, on top of PHP's test. "PHP
     * lets it through" below means PHP 8.5.10's global-range filter accepts
     * it (tested), so the entry here is what refuses it:
     *   fec0::/10       — the old "site-local" range (private, withdrawn).
     *                     PHP lets it through.
     *   ff00::/8        — multicast. PHP lets it through.
     *   64:ff9b::/96    — and 64:ff9b:1::/48: translation prefixes that
     *                     carry an IPv4 address inside. Through a translator
     *                     they can reach a private IPv4 address. PHP lets
     *                     both through.
     *   2002::/16       — "6to4", which also carries an IPv4 address inside.
     *                     PHP already refuses it; listed in case a PHP
     *                     version does not.
     *   ::ffff:0:0:0/96 — the "IPv4-translated" spelling. PHP lets
     *                     `::ffff:0:7f00:1` through, which holds 127.0.0.1.
     *                     Refused outright, whatever is inside.
     *   ::/96           — the old "IPv4-compatible" spelling, withdrawn by
     *                     RFC 4291. Refused outright, whatever is inside.
     *                     Whether PHP's filter covers for this entry depends
     *                     on how the system writes the standard spelling:
     *                     on this development Mac `::7f00:1` comes out as
     *                     `::127.0.0.1`, which PHP refuses, but `::2` (the
     *                     same range, holding 0.0.0.2) comes out as `::2`,
     *                     which PHP accepts. So this entry is load-bearing.
     * No public calendar host is reached through any of these, so refusing
     * them costs nothing. Whether the two IPv4-in-IPv6 spellings would reach
     * anything on a real host was not tested; refusing them does not depend
     * on the answer.
     */
    private const IPV6_REFUSED_RANGES = [
        ['fec0::', 10],
        ['ff00::', 8],
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['2002::', 16],
        ['::ffff:0:0:0', 96],
        ['::', 96],
    ];

    /** The "IPv4-mapped" spelling, ::ffff:a.b.c.d. The IPv4 part inside must pass the same test. */
    private const IPV4_MAPPED_RANGE = ['::ffff:0:0', 96];

    // =========================================================================
    // ⚙️ Download options
    // =========================================================================

    /**
     * Every option `get()` accepts, with its default. An option name that is
     * not listed here throws: a mistyped name (`maxbytes`) would otherwise
     * silently fall back to the default, which is exactly the kind of quiet
     * mistake that turns a size cap into no cap.
     *
     *   maxBytes              — largest body read, in bytes, measured AFTER
     *                           any compression is undone (so a small
     *                           compressed file cannot expand past it).
     *   timeoutSeconds        — the most time one request (one hop) may take.
     *   connectTimeoutSeconds — the most time connecting may take.
     *   maxRedirects          — how many redirects are followed. One more is
     *                           refused.
     *   httpsOnly             — refuse plain http, from the first address on.
     *   accept                — the Accept header sent.
     *   deadline              — a `microtime(true)` moment. No request runs
     *                           past it, across every hop together.
     */
    private const DEFAULT_OPTIONS = [
        'maxBytes'              => 5242880,
        'timeoutSeconds'        => 20,
        'connectTimeoutSeconds' => 5,
        'maxRedirects'          => 3,
        'httpsOnly'             => false,
        'accept'                => '*/*',
        'deadline'              => null,
    ];

    /**
     * TEST ONLY. Always null in the running portal; nothing under `web/`
     * sets it (tools/safefetch-selftest.php checks that on every run).
     *
     * The self-test (and part P6's test set-up, in a scratch copy) needs
     * `get()` to reach a small test server on this machine, at 127.0.0.1 on
     * a high port. Both would normally be refused, rightly. This lets a test
     * say "the name calendar.test means 127.0.0.1, and its server listens on
     * port 9053" without weakening the rule for any real name.
     *
     * Shape: `['calendar.test' => ['ips' => ['127.0.0.1'], 'port' => 9053]]`.
     *   ips  — used instead of looking the name up, and NOT tested with
     *          `isPublicIp()` (that is the whole point). The download is
     *          still pinned to exactly these addresses, and the
     *          pre-request check still insists on them.
     *   port — optional: the port the test server really listens on. The
     *          address itself keeps port 80 or 443, which `normaliseUrl()`
     *          insists on; curl is told to connect to this port instead.
     *
     * The refused names (`localhost` and the rest) are refused BEFORE this
     * is consulted, so even a test cannot re-open them through it.
     *
     * @var array<string, array{ips: list<string>, port?: int}>|null
     */
    public static ?array $testResolverOverride = null;

    // =========================================================================
    // 🧹 normaliseUrl() — one standard spelling, or nothing
    // =========================================================================

    /**
     * Tidy `$input` into the one spelling the rest of the portal stores and
     * fetches, or return null when it cannot be used.
     *
     * Refused: an empty address; one over 2000 bytes; any control
     * character, invisible formatting character or white space inside it
     * (or text that is not valid UTF-8); any scheme other than http, https,
     * webcal and webcals; a missing host; a user name or password; a port
     * other than 80 for http or 443 for https; a host name with characters
     * a real host name cannot have.
     *
     * Changed: the scheme is written in lower case, and webcal/webcals
     * become https; the host is written in lower case with any trailing dot
     * removed; a host with non-English letters is converted to its plain
     * "xn--" form; the fragment (anything after `#`, which is never sent to
     * the server anyway) is dropped; a default port written out is dropped;
     * an empty path becomes `/`.
     *
     * Not changed: the path and the query, which are kept exactly as typed.
     * They matter only to the other server.
     */
    public static function normaliseUrl(string $input): ?string
    {
        $parts = self::parse($input);
        return $parts === null ? null : $parts['url'];
    }

    // =========================================================================
    // 🔢 isPublicIp() — an ordinary public address, and nothing else
    // =========================================================================

    /**
     * True only for an ordinary public IPv4 or IPv6 address.
     *
     * HOW IT DECIDES
     * - The text must be a well-formed address (`FILTER_VALIDATE_IP`). This
     *   refuses spellings that different software reads differently:
     *   `010.0.0.1` (a person reads 10.0.0.1, but curl and the system's own
     *   name lookup read the leading zero as "octal" and get 8.0.0.1, tested
     *   on this development Mac), an IPv6 address with a "%zone" on the
     *   end, a number with spaces.
     * - Everything after that works on the 16 (or 4) bytes `inet_pton()`
     *   gives, never on the text. So every spelling of one address gets the
     *   same answer.
     * - PHP's `FILTER_FLAG_GLOBAL_RANGE` must accept the address, run on the
     *   standard spelling `inet_ntop()` writes from those bytes. Running it on
     *   the text as typed was rejected: tested on PHP 8.5.10, it refuses
     *   `2606:4700::8.8.8.8` but accepts `2606:4700::808:808`, which are the
     *   same address, so the answer would depend on how it was written.
     * - The extra ranges listed above are refused, matched with bit masks.
     * - For the "IPv4-mapped" spelling (::ffff:a.b.c.d), the IPv4 address
     *   inside must pass this same test. (PHP 8.5.10 already refuses every
     *   mapped address; this is a second line of defence in case a later
     *   PHP changes that. The self-test reaches it directly, through
     *   `passesOwnRanges()`.)
     */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $bytes = @inet_pton($ip);
        if ($bytes === false) {
            return false;
        }
        return self::bytesArePublic($bytes);
    }

    // =========================================================================
    // 🔎 check() — may this address be fetched at all?
    // =========================================================================

    /**
     * Decide whether `$url` may be fetched, and if so, from which addresses.
     *
     * In order: normalise it (see `normaliseUrl()`); refuse the reserved
     * names; for a numeric address, test it with `isPublicIp()`; for a name,
     * look it up (`dns_get_record()` for IPv4 and IPv6 answers, falling back
     * to `gethostbynamel()` when that fails outright) and refuse when
     * NOTHING comes back or when ANY answer is not public. One private
     * answer among several public ones is enough to refuse: the download
     * could otherwise be steered to it.
     *
     * Every refusal carries `REFUSED_MESSAGE`, whatever the reason.
     *
     * `ips` lists the approved addresses, in their standard spelling. `get()`
     * connects to these and nothing else.
     *
     * @return array{ok: bool, message: string, host: string, port: int, ips: list<string>}
     */
    public static function check(string $url): array
    {
        $parts = self::parse($url);
        if ($parts === null) {
            return self::refusal('');
        }
        $host = $parts['host'];

        // 🔢 A numeric address: nothing to look up, just test it.
        if ($parts['ipLiteral'] !== null) {
            if (self::isPublicIp($parts['ipLiteral']) === false) {
                return self::refusal($host);
            }
            return ['ok' => true, 'message' => '', 'host' => $host, 'port' => $parts['port'], 'ips' => [$parts['ipLiteral']]];
        }

        // 🏠 Reserved names are refused before anything else — including the
        //    test override, so no test can re-open them.
        if (self::isRefusedName($host) === true) {
            return self::refusal($host);
        }

        // 🧪 Test only (see $testResolverOverride). Null in the running portal.
        $override = self::testOverrideFor($host);
        if ($override !== null) {
            return ['ok' => true, 'message' => '', 'host' => $host, 'port' => $parts['port'], 'ips' => $override['ips']];
        }

        // 🌐 Look the name up. Every answer must be public.
        $ips = self::resolve($host);
        if (self::allPublic($ips) === false) {
            return self::refusal($host);
        }
        return ['ok' => true, 'message' => '', 'host' => $host, 'port' => $parts['port'], 'ips' => $ips];
    }

    /**
     * True only when the list is not empty and EVERY address in it passes
     * `isPublicIp()`. An empty list means the name was not found, which is
     * refused. One private answer among public ones is enough to refuse:
     * curl tries the approved answers in turn until one connects, so any one
     * of them could end up being the one used.
     *
     * Its own method so the self-test can hand it several answers at once.
     * It cannot be tested through a real name lookup without a name server
     * that gives a mixed answer on demand, which the self-test does not have.
     *
     * @param list<string> $ips
     */
    private static function allPublic(array $ips): bool
    {
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (self::isPublicIp($ip) === false) {
                return false;
            }
        }
        return true;
    }

    // =========================================================================
    // ⬇️ get() — download it, with every guard in place
    // =========================================================================

    /**
     * Download `$url` and hand back its body.
     *
     * FOR EACH HOP (the first address, then each redirect):
     * - `check()` the address. The download connects ONLY to the addresses
     *   it approved (`CURLOPT_RESOLVE`), so the name cannot be re-pointed
     *   between the check and the connection.
     * - Where libcurl allows it (7.80 and later), a "pre-request" check
     *   stops the connection before anything is sent unless curl really is
     *   connected to one of the approved addresses. If curl refuses to set
     *   that check (an older libcurl), the fetch goes ahead: the pin above
     *   still holds.
     * - Proxies are switched off (`CURLOPT_PROXY => ''`,
     *   `CURLOPT_NOPROXY => '*'`), including any set through the server's
     *   environment. A proxy would look the name up itself, out of sight of
     *   the check.
     * - Redirects are never followed by curl. They are followed here, one
     *   at a time, each checked like the first address. A relative redirect
     *   is resolved against the current address. A redirect from https to
     *   plain http is refused. One more redirect than `maxRedirects` is
     *   refused.
     * - The body stops being read the moment it passes `maxBytes`; the
     *   result then says `capped` and is not ok.
     * - Each hop gets the smaller of `timeoutSeconds` and the time left
     *   before `deadline`. Milliseconds are used (`CURLOPT_TIMEOUT_MS`),
     *   not whole seconds: with whole seconds, 0.9 seconds left would round
     *   down to 0, which curl reads as "no time limit at all".
     *
     * RESULT: `ok` only for an HTTP 200 that was not capped. `status` is the
     * last HTTP status received (0 when nothing was received). `bytes` counts
     * the body bytes received on the last hop. `body` is empty unless `ok`.
     * `message` is plain English, empty when ok, and never contains the
     * address.
     *
     * @param array{maxBytes?: int, timeoutSeconds?: int, connectTimeoutSeconds?: int, maxRedirects?: int, httpsOnly?: bool, accept?: string, deadline?: float|int|null} $opts
     * @return array{ok: bool, status: int, body: string, bytes: int, message: string, capped: bool}
     * @throws \InvalidArgumentException on an unknown option name or a nonsensical option value
     *         (a programming mistake, never something a visitor can cause).
     */
    public static function get(string $url, array $opts): array
    {
        $o = self::options($opts);

        if (function_exists('curl_init') === false) {
            return self::result(false, 0, '', 0, self::MSG_NO_CURL, false);
        }

        $current = self::parse($url);
        if ($current === null) {
            self::logProblem('FETCH_REFUSED', '', 0);
            return self::result(false, 0, '', 0, self::REFUSED_MESSAGE, false);
        }
        if ($o['httpsOnly'] === true && $current['scheme'] !== 'https') {
            return self::result(false, 0, '', 0, self::MSG_HTTPS_ONLY, false);
        }

        $redirects  = 0;
        $lastStatus = 0; // the redirect status that led here, reported if a later hop is refused
        while (true) {
            // 🔎 Every hop is checked, the first one included.
            $checked = self::check($current['url']);
            if ($checked['ok'] === false) {
                self::logProblem('FETCH_REFUSED', $current['host'], $lastStatus);
                return self::result(false, $lastStatus, '', 0, self::REFUSED_MESSAGE, false);
            }

            // ⏱️ This hop's time limit: the smaller of the per-request limit
            //    and whatever is left before the overall deadline.
            $timeoutMs = $o['timeoutSeconds'] * 1000;
            if ($o['deadline'] !== null) {
                $leftMs = (int) floor(((float) $o['deadline'] - microtime(true)) * 1000);
                if ($leftMs <= 0) {
                    self::logProblem('FETCH_TOO_SLOW', $current['host'], $lastStatus);
                    return self::result(false, $lastStatus, '', 0, self::MSG_TOO_SLOW, false);
                }
                $timeoutMs = min($timeoutMs, $leftMs);
            }

            $state  = self::newHopState();
            $handle = self::buildHandle($current['url'], $checked['host'], $checked['port'], $checked['ips'], $o, $timeoutMs, $state);
            if ($handle === null) {
                self::logProblem('FETCH_UNREACHABLE', $current['host'], $lastStatus);
                return self::result(false, $lastStatus, '', 0, self::MSG_UNREACHABLE, false);
            }
            curl_exec($handle);
            $errno = curl_errno($handle);
            // The handle is released when it goes out of scope. curl_close()
            // is deliberately not called: it has done nothing since PHP 8.0
            // and is deprecated from PHP 8.5.
            unset($handle);

            $status = $state->status;
            if ($state->prereqRefused === true) {
                self::logProblem('FETCH_REFUSED', $current['host'], $status);
                return self::result(false, $status, '', $state->received, self::REFUSED_MESSAGE, false);
            }
            if ($state->capped === true) {
                self::logProblem('FETCH_TOO_BIG', $current['host'], $status);
                return self::result(false, $status, '', $state->received, self::MSG_TOO_BIG, true);
            }
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                self::logProblem('FETCH_TOO_SLOW', $current['host'], $status);
                return self::result(false, $status, '', $state->received, self::MSG_TOO_SLOW, false);
            }
            if ($errno !== 0) {
                self::logProblem('FETCH_UNREACHABLE', $current['host'], $status);
                return self::result(false, $status, '', $state->received, self::MSG_UNREACHABLE, false);
            }

            // ↪️ A redirect: work out the next address and check it next time
            //    round the loop.
            if (in_array($status, self::REDIRECT_STATUSES, true) === true && $state->location !== '') {
                $hop = self::redirectHop($current, $state->location);
                if ($hop['next'] === null) {
                    self::logProblem($hop['code'], $current['host'], $status);
                    return self::result(false, $status, '', 0, $hop['message'], false);
                }
                $redirects++;
                if ($redirects > $o['maxRedirects']) {
                    self::logProblem('FETCH_TOO_MANY_REDIRECTS', $current['host'], $status);
                    return self::result(false, $status, '', 0, self::MSG_TOO_MANY, false);
                }
                $current    = $hop['next'];
                $lastStatus = $status;
                continue;
            }

            if ($status !== 200) {
                self::logProblem('FETCH_HTTP_STATUS', $current['host'], $status);
                return self::result(false, $status, '', $state->received, sprintf(self::MSG_STATUS, $status), false);
            }
            return self::result(true, 200, $state->body, $state->received, '', false);
        }
    }

    // =========================================================================
    // 🧰 Internals
    // =========================================================================

    /**
     * The one address parser, shared by `normaliseUrl()`, `check()` and
     * `get()`, so the three can never disagree about what an address means.
     *
     * The address is split with one strict pattern (scheme `://` authority,
     * path, query, fragment) rather than PHP's `parse_url()`. `parse_url()`
     * is forgiving about malformed addresses, and a forgiving parser that
     * reads an address differently from curl is exactly how request-forgery
     * guards are usually got round. So anything unusual is refused here
     * instead: an `@` or a backslash anywhere in the host part, a host name
     * with characters a real host cannot have, a host whose last part is a
     * number but which is not a plain dotted IPv4 address (`2130706433`,
     * `0x7f.1`, `127.1`, `0177.0.0.1` — curl reads every one of these as
     * 127.0.0.1 and connects to this machine, tested with libcurl 8.22).
     *
     * @return array{scheme: string, host: string, port: int, url: string, ipLiteral: ?string}|null
     */
    private static function parse(string $input): ?array
    {
        $s = trim($input);
        if ($s === '' || strlen($s) > self::MAX_URL_LENGTH) {
            return null;
        }
        // 🚫 Control characters and ordinary white space (ASCII).
        if (preg_match('/[\x00-\x20\x7F]/', $s) === 1) {
            return null;
        }
        // 🚫 Unicode control, invisible formatting (such as a zero-width
        //    space or a right-to-left override) and white space. preg_match
        //    returns false for text that is not valid UTF-8, which is also
        //    refused: only an exact 0 ("found nothing") is accepted.
        if (preg_match('/[\p{Cc}\p{Cf}\p{Z}]/u', $s) !== 0) {
            return null;
        }
        if (preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*)://([^/?#]*)([^?#]*)(\?[^#]*)?(?:#.*)?$~s', $s, $m) !== 1) {
            return null;
        }

        $scheme = self::SCHEMES[strtolower($m[1])] ?? null;
        if ($scheme === null) {
            return null;
        }
        $authority = $m[2];
        $path      = $m[3];
        $query     = $m[4] ?? '';

        // 🚫 No user name or password (anything with an `@`), and no
        //    backslash, which some software reads as a slash.
        if ($authority === '' || str_contains($authority, '@') === true || str_contains($authority, '\\') === true) {
            return null;
        }

        $portText = null;
        if ($authority[0] === '[') {
            // 🔢 An IPv6 address written in square brackets.
            $close = strpos($authority, ']');
            if ($close === false) {
                return null;
            }
            $inner = substr($authority, 1, $close - 1);
            $after = substr($authority, $close + 1);
            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $bytes = @inet_pton($inner);
            $canonical = $bytes === false ? false : inet_ntop($bytes);
            if ($canonical === false) {
                return null;
            }
            $ipLiteral = $canonical;
            $host      = '[' . $canonical . ']';
            if ($after !== '') {
                if ($after[0] !== ':') {
                    return null;
                }
                $portText = substr($after, 1);
            }
        } else {
            $colon   = strrpos($authority, ':');
            $rawHost = $colon === false ? $authority : substr($authority, 0, $colon);
            if ($colon !== false) {
                $portText = substr($authority, $colon + 1);
            }
            $host = self::normaliseHostName($rawHost);
            if ($host === null) {
                return null;
            }
            $ipLiteral = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $host : null;
        }

        // 🚪 Only the scheme's own port, and then it is simply dropped.
        $port = self::DEFAULT_PORTS[$scheme];
        if ($portText !== null) {
            if (preg_match('/^[0-9]{1,5}$/', $portText) !== 1 || (int) $portText !== $port) {
                return null;
            }
        }

        $url = $scheme . '://' . $host . ($path === '' ? '/' : $path) . $query;
        if (strlen($url) > self::MAX_URL_LENGTH) {
            return null;
        }
        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'url' => $url, 'ipLiteral' => $ipLiteral];
    }

    /**
     * Lower case, trailing dots removed, non-English letters converted to
     * the plain "xn--" form, then held to `HOST_PATTERN`. Null when the name
     * cannot be used.
     *
     * The conversion can turn look-alike characters into ordinary ones:
     * full-width `１２７.０.０.１` becomes `127.0.0.1`, and full-width
     * `ｌｏｃａｌｈｏｓｔ` becomes `localhost` (tested with PHP's intl on
     * 8.5.10). That is why the trailing-dot removal, the numeric-address
     * test and the reserved-name test all run on the CONVERTED name.
     */
    private static function normaliseHostName(string $raw): ?string
    {
        $host = rtrim(strtolower($raw), '.');
        if ($host === '') {
            return null;
        }
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            if (function_exists('idn_to_ascii') === false) {
                // Without PHP's intl extension a name with non-English letters
                // cannot be converted safely, so it is refused.
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = rtrim(strtolower($ascii), '.');
        }
        if ($host === '' || strlen($host) > 253 || preg_match(self::HOST_PATTERN, $host) !== 1) {
            return null;
        }

        // 🔢 A name whose last part is a number is read as an IPv4 address
        //    by curl (and, under the web's own address rules, by browsers).
        //    Only the plain dotted form is accepted, so there is exactly one
        //    way to write a numeric address, and `check()` always sees it as
        //    a number. (Without this rule `check()` would still refuse these
        //    spellings, because the name server finds nothing for them; this
        //    rule makes the refusal not depend on that.)
        $labels = explode('.', $host);
        $last   = (string) end($labels);
        if (preg_match('/^(?:[0-9]+|0x[0-9a-f]*)$/', $last) === 1
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        ) {
            return null;
        }
        return $host;
    }

    /** True for `localhost` and the other names that only exist on a local network. */
    private static function isRefusedName(string $host): bool
    {
        foreach (self::REFUSED_NAME_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every IPv4 and IPv6 address `$host` points at, in standard spelling,
     * each once. An empty list means the name could not be found.
     *
     * An answer that is not a valid address is kept as it came, so that
     * `isPublicIp()` refuses it and the whole name with it (fail closed).
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $found = [];
        // "@" because dns_get_record() raises a PHP warning for a name that
        // does not exist; that case is handled by the empty list below.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4) === true) {
                $found = $v4;
            }
        } else {
            foreach ($records as $record) {
                $type = (string) ($record['type'] ?? '');
                if ($type === 'A') {
                    $found[] = (string) ($record['ip'] ?? '');
                } elseif ($type === 'AAAA') {
                    $found[] = (string) ($record['ipv6'] ?? '');
                }
            }
        }

        $ips = [];
        foreach ($found as $ip) {
            $bytes = @inet_pton($ip);
            $ips[] = $bytes === false ? $ip : (string) inet_ntop($bytes);
        }
        return array_values(array_unique($ips));
    }

    /** The test override for `$host`, or null (always null in the running portal). */
    private static function testOverrideFor(string $host): ?array
    {
        if (self::$testResolverOverride === null || isset(self::$testResolverOverride[$host]) === false) {
            return null;
        }
        $entry = self::$testResolverOverride[$host];
        $ips   = [];
        foreach ((array) ($entry['ips'] ?? []) as $ip) {
            $bytes = @inet_pton((string) $ip);
            if ($bytes !== false) {
                $ips[] = (string) inet_ntop($bytes);
            }
        }
        if ($ips === []) {
            return null;
        }
        $out = ['ips' => $ips];
        if (isset($entry['port']) === true) {
            $out['port'] = (int) $entry['port'];
        }
        return $out;
    }

    /**
     * The byte-level test behind `isPublicIp()`: PHP's global-range filter
     * on the standard spelling, THEN this class's own range table. Both must
     * say yes. `$bytes` is 4 or 16 bytes from `inet_pton()`.
     */
    private static function bytesArePublic(string $bytes): bool
    {
        $canonical = @inet_ntop($bytes);
        if ($canonical === false) {
            return false;
        }
        if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        return self::passesOwnRanges($bytes);
    }

    /**
     * This class's own range table on its own, with no help from PHP's
     * filter: false when `$bytes` is inside any refused range, or is an
     * "IPv4-mapped" address whose IPv4 part fails the whole test.
     *
     * Kept apart from `bytesArePublic()` so the self-test can check every
     * entry of the table directly. Through `isPublicIp()` alone it could
     * not: PHP's filter already refuses several of these ranges, and which
     * ones depends on the PHP version and on how the system writes the
     * standard spelling, so a missing entry could pass unnoticed on one
     * machine and leak on another.
     */
    private static function passesOwnRanges(string $bytes): bool
    {
        if (strlen($bytes) === 4) {
            foreach (self::IPV4_REFUSED_RANGES as [$prefix, $bits]) {
                if (self::inRange($bytes, $prefix, $bits) === true) {
                    return false;
                }
            }
            return true;
        }
        if (strlen($bytes) !== 16) {
            return false;
        }
        foreach (self::IPV6_REFUSED_RANGES as [$prefix, $bits]) {
            if (self::inRange($bytes, $prefix, $bits) === true) {
                return false;
            }
        }
        // ::ffff:a.b.c.d — the IPv4 address inside must pass on its own.
        if (self::inRange($bytes, self::IPV4_MAPPED_RANGE[0], self::IPV4_MAPPED_RANGE[1]) === true) {
            return self::bytesArePublic(substr($bytes, 12, 4));
        }
        return true;
    }

    /** True when the first `$bits` bits of `$bytes` equal those of `$prefix`. */
    private static function inRange(string $bytes, string $prefix, int $bits): bool
    {
        $prefixBytes = inet_pton($prefix);
        if ($prefixBytes === false || strlen($prefixBytes) !== strlen($bytes)) {
            return false;
        }
        $whole = intdiv($bits, 8);
        $rest  = $bits % 8;
        if ($whole > 0 && substr($bytes, 0, $whole) !== substr($prefixBytes, 0, $whole)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($bytes[$whole]) & $mask) === (ord($prefixBytes[$whole]) & $mask);
    }

    /**
     * Merge the caller's options over the defaults, refusing an unknown name
     * or a value that makes no sense.
     *
     * @return array{maxBytes: int, timeoutSeconds: int, connectTimeoutSeconds: int, maxRedirects: int, httpsOnly: bool, accept: string, deadline: float|int|null}
     */
    private static function options(array $opts): array
    {
        foreach (array_keys($opts) as $name) {
            if (array_key_exists((string) $name, self::DEFAULT_OPTIONS) === false) {
                throw new \InvalidArgumentException('SafeFetch::get(): unknown option "' . $name . '".');
            }
        }
        $o = array_merge(self::DEFAULT_OPTIONS, $opts);
        foreach (['maxBytes', 'timeoutSeconds', 'connectTimeoutSeconds'] as $name) {
            if (is_int($o[$name]) === false || $o[$name] < 1) {
                throw new \InvalidArgumentException('SafeFetch::get(): option "' . $name . '" must be a whole number of at least 1.');
            }
        }
        if (is_int($o['maxRedirects']) === false || $o['maxRedirects'] < 0 || $o['maxRedirects'] > 10) {
            throw new \InvalidArgumentException('SafeFetch::get(): option "maxRedirects" must be a whole number from 0 to 10.');
        }
        if (is_bool($o['httpsOnly']) === false) {
            throw new \InvalidArgumentException('SafeFetch::get(): option "httpsOnly" must be true or false.');
        }
        // The Accept value becomes a request header; a line break in it would
        // let it add headers of its own.
        if (is_string($o['accept']) === false || $o['accept'] === '' || preg_match('/[\x00-\x1F\x7F]/', $o['accept']) === 1) {
            throw new \InvalidArgumentException('SafeFetch::get(): option "accept" must be a single line of text.');
        }
        if ($o['deadline'] !== null && is_float($o['deadline']) === false && is_int($o['deadline']) === false) {
            throw new \InvalidArgumentException('SafeFetch::get(): option "deadline" must be a microtime(true) moment or null.');
        }
        return $o;
    }

    /** A fresh record of what one hop received, written by curl's callbacks. */
    private static function newHopState(): \stdClass
    {
        $state                = new \stdClass();
        $state->status        = 0;
        $state->location      = '';
        $state->body          = '';
        $state->received      = 0;
        $state->capped        = false;
        $state->prereqRefused = false;
        return $state;
    }

    /**
     * One configured curl handle for one hop. Kept separate from `get()` so
     * the self-test can prove, on the very handle built here, that the
     * pre-request check stops a connection to an address that was not
     * approved. Null when curl cannot start, or refuses any of the guard
     * options (then nothing is fetched at all).
     *
     * @param list<string> $ips The approved addresses from `check()`.
     */
    private static function buildHandle(string $url, string $host, int $port, array $ips, array $o, int $timeoutMs, \stdClass $state): ?\CurlHandle
    {
        $handle = curl_init();
        if ($handle === false) {
            return null; // curl could not start (for example, out of memory)
        }

        // 📌 The pin: "this host, on this port, is at exactly these
        //    addresses". IPv6 addresses go in square brackets. libcurl tries
        //    them in turn and never asks a name server.
        $pinned = [];
        foreach ($ips as $ip) {
            $pinned[] = str_contains($ip, ':') === true ? '[' . $ip . ']' : $ip;
        }
        $resolve   = [$host . ':' . $port . ':' . implode(',', $pinned)];
        $connectTo = [];

        // 🧪 Test only: send the connection to the test server's real port.
        //    The name still goes through the pin above, now for that port.
        $override = self::testOverrideFor($host);
        if ($override !== null && isset($override['port']) === true) {
            $connectTo[] = $host . ':' . $port . ':' . $host . ':' . $override['port'];
            $resolve[]   = $host . ':' . $override['port'] . ':' . implode(',', $pinned);
        }

        $protocols = $o['httpsOnly'] === true ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS);
        $maxBytes  = $o['maxBytes'];

        // curl_setopt_array() stops at the first option curl refuses and
        // skips every option after it, so a refusal would leave, say, the
        // proxy or redirect settings at curl's defaults. Its answer is
        // therefore checked, and a handle it could not fully set up is
        // never used (null: the caller reports "could not be reached").
        $allSet = curl_setopt_array($handle, [
            CURLOPT_URL               => $url,
            CURLOPT_RESOLVE           => $resolve,
            CURLOPT_PROXY             => '',
            CURLOPT_NOPROXY           => '*',
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_PROTOCOLS         => $protocols,
            CURLOPT_REDIR_PROTOCOLS   => $protocols,
            CURLOPT_CONNECTTIMEOUT_MS => min($o['connectTimeoutSeconds'] * 1000, $timeoutMs),
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            // Millisecond time limits need curl not to use alarm signals.
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            // '' = accept every compression curl understands. The size cap
            // below counts the bytes AFTER decompression.
            CURLOPT_ENCODING          => '',
            CURLOPT_USERAGENT         => self::userAgent(),
            CURLOPT_HTTPHEADER        => ['Accept: ' . $o['accept']],
            CURLOPT_HEADERFUNCTION    => static function ($h, string $line) use ($state): int {
                $text = rtrim($line, "\r\n");
                if (preg_match('~^HTTP/\S+\s+([0-9]{3})~', $text, $m) === 1) {
                    // A new status line starts a new set of headers.
                    $state->status   = (int) $m[1];
                    $state->location = '';
                } elseif (stripos($text, 'location:') === 0) {
                    $state->location = trim(substr($text, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION     => static function ($h, string $chunk) use ($state, $maxBytes): int {
                $state->received += strlen($chunk);
                if ($state->received > $maxBytes) {
                    $state->capped = true;
                    return 0; // a short answer tells curl to stop the transfer
                }
                $state->body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($allSet === false) {
            return null;
        }
        if ($connectTo !== [] && curl_setopt($handle, CURLOPT_CONNECT_TO, $connectTo) === false) {
            return null;
        }

        // 🛂 The pre-request check (libcurl 7.80 and later). When the option
        //    does not exist, or curl refuses it, the fetch carries on: the pin
        //    above still decides where curl connects.
        if (defined('CURLOPT_PREREQFUNCTION') === true) {
            $approved = [];
            foreach ($ips as $ip) {
                $bytes = @inet_pton($ip);
                if ($bytes !== false) {
                    $approved[] = $bytes;
                }
            }
            curl_setopt($handle, CURLOPT_PREREQFUNCTION, static function ($h) use ($approved, $state): int {
                $bytes = @inet_pton((string) curl_getinfo($h, CURLINFO_PRIMARY_IP));
                if ($bytes !== false && in_array($bytes, $approved, true) === true) {
                    return CURL_PREREQFUNC_OK;
                }
                $state->prereqRefused = true;
                return CURL_PREREQFUNC_ABORT;
            });
        }
        return $handle;
    }

    /**
     * The User-Agent: the product name and what the request is for, and no
     * web address (#500: nothing about one customer's set-up is built in).
     * Control characters are removed, because the product name is an
     * administrator setting and a line break in a header would start a new
     * header.
     */
    private static function userAgent(): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', Site::productName()) ?? '';
        return trim($name . ' calendar import');
    }

    /**
     * Decide where a redirect leads: the next address to fetch, already
     * parsed, or the reason it is refused. Refused: a `Location` that does
     * not make a usable address (the one message, as for any refused
     * address), and a move from https down to plain http, which would send
     * the rest of the conversation unencrypted.
     *
     * This only decides WHAT the next address is. Whether it may be fetched
     * (a private network, a reserved name) is decided by `check()` when the
     * loop in `get()` reaches it, exactly as for the first address.
     *
     * Its own method so the self-test can prove the https-to-http refusal
     * directly: the self-test's server speaks only plain http, because a
     * local https server would need a certificate this class's strict
     * certificate check rightly refuses.
     *
     * @param array{scheme: string, host: string, port: int, url: string, ipLiteral: ?string} $current
     * @return array{next: ?array, code: string, message: string} `next` is null when refused.
     */
    private static function redirectHop(array $current, string $location): array
    {
        $nextUrl = self::resolveRedirect($current['url'], $location);
        $next    = $nextUrl === null ? null : self::parse($nextUrl);
        if ($next === null) {
            return ['next' => null, 'code' => 'FETCH_REFUSED', 'message' => self::REFUSED_MESSAGE];
        }
        if ($current['scheme'] === 'https' && $next['scheme'] === 'http') {
            return ['next' => null, 'code' => 'FETCH_DOWNGRADE', 'message' => self::MSG_DOWNGRADE];
        }
        return ['next' => $next, 'code' => '', 'message' => ''];
    }

    /**
     * Turn a redirect's `Location` into a full address, following the
     * standard rules for relative addresses (RFC 3986 section 5.2). `$base`
     * is the current address, already normalised. The result is checked by
     * the caller like any other address; this only works out WHAT it is.
     */
    private static function resolveRedirect(string $base, string $location): ?string
    {
        $ref = trim($location);
        if ($ref === '') {
            return null;
        }
        // A full address, with its own scheme.
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:~', $ref) === 1) {
            return $ref;
        }
        if (preg_match('~^([a-z]+)://([^/?#]*)([^?#]*)(\?[^#]*)?~', $base, $b) !== 1) {
            return null;
        }
        $scheme    = $b[1];
        $authority = $b[2];
        $basePath  = $b[3] === '' ? '/' : $b[3];
        $baseQuery = $b[4] ?? '';

        // "//host/path": same scheme, different host.
        if (str_starts_with($ref, '//') === true) {
            return $scheme . ':' . $ref;
        }
        $ref = explode('#', $ref, 2)[0];
        if ($ref === '') {
            return $scheme . '://' . $authority . $basePath . $baseQuery;
        }
        $q        = strpos($ref, '?');
        $refPath  = $q === false ? $ref : substr($ref, 0, $q);
        $refQuery = $q === false ? '' : substr($ref, $q);

        if ($refPath === '') {
            $path  = $basePath;
            $query = $q === false ? $baseQuery : $refQuery;
        } elseif ($refPath[0] === '/') {
            $path  = self::removeDotSegments($refPath);
            $query = $refQuery;
        } else {
            $dir   = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path  = self::removeDotSegments($dir . $refPath);
            $query = $refQuery;
        }
        return $scheme . '://' . $authority . $path . $query;
    }

    /** Remove `.` and `..` parts from a path that starts with `/` (RFC 3986 section 5.2.4). */
    private static function removeDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $last     = count($segments) - 1;
        $out      = [];
        foreach ($segments as $i => $segment) {
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..' && count($out) > 1) {
                    array_pop($out);
                }
                if ($i === $last) {
                    $out[] = '';
                }
                continue;
            }
            $out[] = $segment;
        }
        $result = implode('/', $out);
        return str_starts_with($result, '/') === true ? $result : '/' . $result;
    }

    /** @return array{ok: bool, message: string, host: string, port: int, ips: list<string>} */
    private static function refusal(string $host): array
    {
        return ['ok' => false, 'message' => self::REFUSED_MESSAGE, 'host' => $host, 'port' => 0, 'ips' => []];
    }

    /** @return array{ok: bool, status: int, body: string, bytes: int, message: string, capped: bool} */
    private static function result(bool $ok, int $status, string $body, int $bytes, string $message, bool $capped): array
    {
        return ['ok' => $ok, 'status' => $status, 'body' => $body, 'bytes' => $bytes, 'message' => $message, 'capped' => $capped];
    }

    /**
     * Record a fetch that did not succeed: the host and the HTTP status, and
     * nothing else — never the path or the query, which for a Google
     * "secret address" is the password to the calendar.
     *
     * The organisation is recorded as "not known" (null) on purpose: this
     * class is not told which organisation it is working for, and
     * `Logger::errorPlatform()` would stamp whichever one the request
     * happened to resolve (the first, in a scheduled job). The caller
     * records the organisation's own outcome itself.
     *
     * A failure to log never changes the result of the fetch.
     */
    private static function logProblem(string $code, string $host, int $status): void
    {
        try {
            Logger::errorPlatformForSite(
                null,
                'SafeFetch',
                'Notice',
                $code,
                'Outside address fetch did not succeed (host ' . ($host === '' ? 'unknown' : $host) . ', HTTP status ' . $status . ')',
                ''
            );
        } catch (\Throwable $ignored) {
            // Logging is best effort here; the caller still gets the result.
        }
    }
}
