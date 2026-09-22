<?php
// Path: tools/safefetch-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Self-test for the safe fetcher, Portal\Core\SafeFetch 🌐🛡️ (#514, part P4)
 * -----------------------------------------------------------------------------
 * SafeFetch is what stands between an address an administrator typed in and
 * the host's own internal network. Almost every mistake in a class like that
 * is silent: the fetch simply works, against the wrong machine. So this
 * script tries each way in, and fails loudly if any of them opens.
 *
 * WHAT IT RUNS
 *   A. normaliseUrl(): the one standard spelling, and what is refused.
 *   B. isPublicIp(): private, reserved and special addresses in many
 *      spellings, including the IPv6 forms that hide an IPv4 address.
 *      B2. SafeFetch's own range table checked entry by entry, on its own,
 *      because PHP's filter already covers some entries (which ones
 *      depends on the PHP version and the system), and would hide a
 *      missing one.
 *   C. check(): reserved names, numeric addresses, names that do not exist,
 *      names that point at this machine — all with the one message.
 *      C2. A name with several answers: refused if ANY answer is private.
 *      C3. Where a redirect leads, including the refusal of https down to
 *      plain http.
 *   D. get() against a small test server this script starts on this machine
 *      (port 9053, or SAFEFETCH_SELFTEST_PORT): a normal download, proof
 *      that plain curl reads the shortened numeric spellings part A refuses
 *      as this machine, the headers sent, proxies ignored, redirects to
 *      private addresses
 *      refused before they are requested, relative redirects, too many
 *      redirects, the size cap, error statuses, https-only, the time limit,
 *      the deadline, and that nothing logged or returned holds the address.
 *   E. The pre-request check on the very curl handle SafeFetch builds: a
 *      connection that ends up at an address that was not approved is
 *      stopped before the request is sent.
 *   F. Pinning against the real internet (a public example address): the
 *      connection goes to an approved address, and a pinned address beats
 *      what the name server says. Prints SKIPPED only when the name does not
 *      resolve here or no outbound connection can be made, and both halves
 *      of that are decided WITHOUT SafeFetch: dns_get_record() for "does the
 *      name resolve", plain curl (st_reachable()) for "can anything here
 *      reach it". If the name resolves, or plain curl reaches the address,
 *      and SafeFetch refuses it, that is a FAIL, not a skip. (The first
 *      version asked SafeFetch::check() whether to skip, so a SafeFetch that
 *      refused every real name still passed; P4's check, mutation K3.)
 *   G. Nothing under web/ sets the test-only override.
 *   H. Hymnal (the hymn lookup) now refuses what SafeFetch refuses, for a
 *      numeric address and (through the public nip.io service, SKIPPED
 *      without it) for a name.
 *   I. The certificate checks, against a local https server made on the
 *      spot with openssl: an untrusted certificate is refused (the peer
 *      check), and a trusted certificate for a DIFFERENT name is refused
 *      (the name check). Each has a control. SKIPPED where openssl is
 *      missing, where it cannot make a certificate, where it will not start
 *      a test server on 127.0.0.1 — which is what macOS's own LibreSSL does,
 *      so on a stock macOS machine the certificate settings are NOT tested —
 *      or where that server will not serve a page at all. (Added after
 *      P4's check: switching off either setting, mutations K1 and K2, left
 *      every earlier check passing.)
 *
 * WHAT IT CANNOT PROVE
 *   - The test server is on 127.0.0.1, which SafeFetch rightly refuses. It is
 *     reached through `SafeFetch::$testResolverOverride`, which says "the
 *     test name calendar.test is at 127.0.0.1, port N". So part D proves
 *     everything about get() EXCEPT that a real name is pinned to its real
 *     addresses; part F proves that, and only when there is a network.
 *   - Part F proves that the connection went to a checked address. It says
 *     nothing about reading a calendar: that is #514 parts P5 and P6, and
 *     "works against a real Google or Microsoft 365 calendar" is NOT proven
 *     by anything here.
 *   - Part I's certificate server answers one page and never redirects, so
 *     the refusal of a redirect from https to http is still not exercised
 *     over a real connection. Part C3 proves it on the redirect decision
 *     itself (`redirectHop()`), which is the step get() uses for every
 *     redirect.
 *   - Part I needs an openssl that will start a working https server on
 *     127.0.0.1. It prints SKIPPED, and the certificate settings are NOT
 *     tested, in four cases: openssl missing; it cannot make a certificate;
 *     it refuses to listen on 127.0.0.1 (macOS's own LibreSSL does: it wants
 *     the port on its own, and this test will not open a port to the whole
 *     network for that — this is the case a stock macOS machine hits); or
 *     the server starts but will not serve a page even with checking
 *     switched off (seen in round 2 from LibreSSL, back when this test still
 *     fell back to the bare-port form; it no longer does). On such a
 *     machine a broken certificate setting is NOT caught here: the round-3
 *     check proved that, with the peer check switched off, the run still
 *     ends 0 under LibreSSL. Use an openssl that works (for example
 *     Homebrew's) when that matters.
 *   - Three faults the independent check planted are knowingly NOT caught,
 *     because each costs little:
 *     K8, the connection time limit ignored: the per-request time limit
 *       still bounds every hop, so a slow connection cannot hang a fetch.
 *     K9, a Location header kept from an earlier block of headers: it
 *       matters only after an interim (1xx) answer that carries one — a
 *       "100 Continue" or a "103 Early Hints" — and every hop is checked
 *       again before it is followed. (The round-2 check proved a 103 does
 *       it too, so "only 100 Continue" was too narrow.)
 *     K13, a trailing dot kept after converting a non-English name: it can
 *       only make SafeFetch refuse more, never less.
 *   - Through isPublicIp() the "IPv4-mapped" second line of defence cannot
 *     be reached on PHP 8.5.10, because PHP already refuses every mapped
 *     address before it. Part B2 reaches it directly instead.
 *   - Part C2 hands SafeFetch's "every answer must be public" step a list
 *     of answers directly. There is no name server here that gives a mixed
 *     public-and-private answer on demand, so it is not exercised through
 *     a real name lookup.
 *   - The rule "no user name or password" (an "@" in the host part) cannot
 *     be shown to work ON ITS OWN: the host-name pattern and the port rule
 *     also refuse every "@" there, so switching off any one of the three
 *     still leaves every such address refused. Part A proves that addresses
 *     carrying a user name or password are refused, whichever rule does it.
 *
 * HOW IT RUNS WITHOUT THE PORTAL
 *   No database and no start-up code. It loads the real Site.php (only for
 *   the product name, which falls back to the built-in default here) and a
 *   small stand-in for Portal\Core\Logger that records what SafeFetch asks
 *   to log, so the test can check that no address ever reaches a log.
 *
 * Usage:  php tools/safefetch-selftest.php
 *         SAFEFETCH_SELFTEST_PORT=9190 php tools/safefetch-selftest.php
 * Exit:   0 when every check passed (SKIPPED lines do not fail it);
 *         1 when any check failed, or the test server could not start.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/514
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// =============================================================================
// 📝 Stand-in for the portal's logger. SafeFetch calls exactly this method.
//    The real Logger writes to the database, which this script does not have.
// =============================================================================
namespace Portal\Core {
    final class Logger
    {
        /** @var list<string> Everything SafeFetch asked to log, as one line each. */
        public static array $records = [];

        public static function errorPlatformForSite(
            ?int $siteId,
            string $platform,
            string $severity,
            string $code,
            string $title,
            string $detail = '',
            ?int $userId = null
        ): void {
            self::$records[] = $platform . ' | ' . $severity . ' | ' . $code . ' | ' . $title . ' | ' . $detail;
        }
    }
}

namespace {
    use Portal\Core\Hymnal;
    use Portal\Core\Logger;
    use Portal\Core\SafeFetch;
    use Portal\Core\Site;

    require __DIR__ . '/../web/_core/Site.php';
    require __DIR__ . '/../web/_core/SafeFetch.php';
    require __DIR__ . '/../web/_core/Hymnal.php';

    // -------------------------------------------------------------------------
    // 🧮 Counting and printing
    // -------------------------------------------------------------------------
    $GLOBALS['st_pass'] = 0;
    $GLOBALS['st_fail'] = 0;
    $GLOBALS['st_skip'] = 0;
    $GLOBALS['st_php_warnings'] = [];

    function st_check(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok === true) {
            $GLOBALS['st_pass']++;
            echo 'PASS — ' . $label . "\n";
            return;
        }
        $GLOBALS['st_fail']++;
        echo 'FAIL — ' . $label . ($detail !== '' ? ' [' . $detail . ']' : '') . "\n";
    }

    function st_skip(string $label, string $why): void
    {
        $GLOBALS['st_skip']++;
        echo 'SKIPPED — ' . $label . ' (' . $why . ")\n";
    }

    function st_show(mixed $value): string
    {
        return var_export($value, true);
    }

    // 🛑 Stopped from outside (Ctrl-C, or a "kill" while it runs)? PHP does
    //    NOT run shutdown functions for a signal, so the test servers would
    //    be left listening (the round-2 check proved it: SIGTERM to PHP
    //    alone orphaned both the certificate server and part D's web
    //    server). Where the pcntl extension is present, the signal is
    //    turned into an ordinary exit, which does run them. What this
    //    cannot do: survive SIGKILL, which no program can catch, and it
    //    does nothing where pcntl is missing (it is absent from many
    //    Windows builds) — there, stop the servers by hand.
    if (function_exists('pcntl_async_signals') === true && function_exists('pcntl_signal') === true) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, static function (int $s): void {
                echo "\nSTOPPED — signal " . $s . " received; stopping the test servers.\n";
                exit(1);
            });
        }
    }

    // Any PHP warning or deprecation raised while testing is itself a fault
    // (a noisy fetcher fills the host's error log). "@"-silenced calls are
    // left alone, as PHP intends.
    set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
        if ((error_reporting() & $no) === 0) {
            return false;
        }
        $GLOBALS['st_php_warnings'][] = $str . ' at ' . basename($file) . ':' . $line;
        return true;
    });

    /** Private SafeFetch methods: parts B2, C2 and C3 test one step on its own; parts E and F call them exactly as get() does. */
    function st_private(string $method, array $args): mixed
    {
        $m = new ReflectionMethod(SafeFetch::class, $method);
        return $m->invoke(null, ...$args);
    }

    /**
     * Can THIS machine reach the given address at all, decided WITHOUT
     * SafeFetch (plain curl, ordinary settings)? Used to tell "there is no
     * outbound network here" (a fair SKIP) from "SafeFetch cannot connect
     * although everything else can" (a FAULT). The round-2 check found the
     * difference: with the pinned addresses joined by ";" instead of ",",
     * every SafeFetch fetch failed while plain curl still worked, and the
     * test only printed SKIPPED.
     *
     * True means plain curl got an answer. Anything else (including a
     * refused connection or a time-out) means no.
     */
    function st_reachable(string $url): bool
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return false;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
        ]);
        curl_exec($handle);
        return curl_errno($handle) === 0;
    }

    $R = SafeFetch::REFUSED_MESSAGE;

    // =========================================================================
    // A. normaliseUrl()
    // =========================================================================
    echo "=== A. normaliseUrl(): one standard spelling, or nothing ===\n";

    $long2001 = 'https://example.org/' . str_repeat('a', 2001 - strlen('https://example.org/'));
    $long2000 = 'https://example.org/' . str_repeat('a', 2000 - strlen('https://example.org/'));
    $normalCases = [
        // The plan's own cases (#514 P4 proof 1).
        ['webcal://example.org/a.ics', 'https://example.org/a.ics'],
        ['WEBCALS://Example.org./x', 'https://example.org/x'],
        ['ftp://example.org/a.ics', null],
        ['file:///etc/passwd', null],
        ['gopher://example.org/', null],
        ['https://u:p@example.org/', null],
        ['https://example.org:8443/', null],
        ["https://exa\tmple.org/", null],
        ["https://example.org/a\tb.ics", null],
        [$long2001, null],
        // Controls and extra cases.
        [$long2000, $long2000],
        ['  https://example.org/x  ', 'https://example.org/x'],
        ['https://example.org', 'https://example.org/'],
        ['https://example.org/cal.ics?key=abc#part', 'https://example.org/cal.ics?key=abc'],
        ['https://example.org:443/x', 'https://example.org/x'],
        ['http://example.org:80/x', 'http://example.org/x'],
        ['http://example.org:443/x', null],
        ['webcal://example.org:80/x', null],
        ['https://example.org:/x', null],
        // A user name or password, in every place an "@" can sit.
        ['https://@example.org/', null],
        ['https://user@example.org/', null],
        ['https://user:@example.org/', null],
        ['https://example.org:443@evil.example/', null],
        ['https://[::1]@example.org/', null],
        ['https://user@[2606:4700::1111]/', null],
        ["https://user\u{FF20}example.org/", null], // a full-width "@", which name conversion turns into a real one
        ['https://user%40example.org/', null],
        ['https://example.org\\@127.0.0.1/', null],
        ['https:example.org/x', null],
        ['//example.org/x', null],
        ['https:///x', null],
        ['data:text/plain,hello', null],
        ['javascript:alert(1)', null],
        ['https://bücher.example/kalender', 'https://xn--bcher-kva.example/kalender'],
        ['http://2130706433/', null],
        ['http://0x7f.1/', null],
        // The one-number hexadecimal form. 0x7f.1 above ends in plain digits,
        // so it is refused even if the hexadecimal half of the numeric-host
        // rule is lost; this one is not (P4's check, mutation K18).
        ['http://0x7f000001/', null],
        ['http://127.1/', null],
        ['http://010.0.0.1/', null],
        ['http://１２７.０.０.１/', 'http://127.0.0.1/'],
        ['http://ｌｏｃａｌｈｏｓｔ/', 'http://localhost/'],
        ["https://example.org/\u{202E}sci.ics", null],
        ["https://exa\u{200B}mple.org/", null],
        ["https://example.org/\xff.ics", null],
        ['https://[::1]/', 'https://[::1]/'],
        ['https://[0:0:0:0:0:0:0:1]:443/x', 'https://[::1]/x'],
        ['https://[fe80::1%25en0]/', null],
        ['https://exa%6dple.org/', null],
        ['https://a..b.example/', null],
    ];
    foreach ($normalCases as [$in, $want]) {
        $got   = SafeFetch::normaliseUrl($in);
        $short = static fn (?string $v): ?string => ($v !== null && strlen($v) > 70) ? substr($v, 0, 40) . '…(' . strlen($v) . ' bytes)' : $v;
        st_check('normaliseUrl(' . st_show($short($in)) . ') → ' . st_show($short($want)), $got === $want, 'got ' . st_show($short($got)));
    }

    // =========================================================================
    // B. isPublicIp()
    // =========================================================================
    echo "\n=== B. isPublicIp(): ordinary public addresses only ===\n";

    $notPublic = [
        // The plan's list (#514 P4 proof 2).
        '127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.0.0.1', '198.18.0.1', '224.0.0.1',
        '240.0.0.1', '0.0.0.0', '::1', 'fe80::1', 'fc00::1', 'fec0::1', 'ff02::1', '64:ff9b::a00:1',
        '2002:a00:1::1', '2001:db8::1', '::ffff:127.0.0.1', '::ffff:7f00:1', '::127.0.0.1',
        '::ffff:0:7f00:1', '::ffff:0:127.0.0.1', '::ffff:0:a00:1', '::7f00:1', '::ffff:0:808:808',
        '::8.8.8.8', '::ffff:8.8.8.8',
        // Extra cases.
        // '::2' is ::0.0.0.2 in the old "IPv4-compatible" range. On this
        // development Mac its standard spelling stays '::2', which PHP's
        // filter ACCEPTS, so only SafeFetch's own ::/96 entry refuses it.
        '::2', '::0.0.0.2',
        '192.0.2.1', '198.51.100.1', '203.0.113.1', '255.255.255.255', '::', '64:ff9b:1::1',
        '2001:0:4136:e378:8000:63bf:3fff:fdd2', '010.0.0.1', '127.1', 'fe80::1%lo0', 'not-an-address', '',
    ];
    foreach ($notPublic as $ip) {
        st_check('isPublicIp(' . st_show($ip) . ') is false', SafeFetch::isPublicIp($ip) === false);
    }
    foreach (['8.8.8.8', '2606:4700::1111', '1.1.1.1', '2001:4860:4860::8888', '2606:4700::8.8.8.8'] as $ip) {
        st_check('isPublicIp(' . st_show($ip) . ') is true', SafeFetch::isPublicIp($ip) === true);
    }

    // Two spellings of the same 16 bytes always get the same answer. PHP's own
    // filter does NOT promise that (it refuses 2606:4700::8.8.8.8 and accepts
    // 2606:4700::808:808); SafeFetch runs it on one standard spelling.
    $pairs = [
        ['::ffff:0:7f00:1', '::ffff:0:127.0.0.1', false],
        ['::7f00:1', '::127.0.0.1', false],
        ['::2', '::0.0.0.2', false],
        ['::ffff:7f00:1', '::ffff:127.0.0.1', false],
        ['0:0:0:0:0:ffff:0808:0808', '::ffff:8.8.8.8', false],
        ['64:ff9b::808:808', '64:ff9b::8.8.8.8', false],
        ['2606:4700::808:808', '2606:4700::8.8.8.8', true],
        ['2606:4700::7f00:1', '2606:4700::127.0.0.1', true],
    ];
    foreach ($pairs as [$a, $b, $want]) {
        $ga = SafeFetch::isPublicIp($a);
        $gb = SafeFetch::isPublicIp($b);
        st_check('same answer for ' . $a . ' and ' . $b . ' (' . st_show($want) . ')', $ga === $want && $gb === $want, 'got ' . st_show($ga) . '/' . st_show($gb));
    }

    // -------------------------------------------------------------------------
    // B2. SafeFetch's own range table, entry by entry, with PHP's filter left
    //     out. Through isPublicIp() a missing entry can hide behind PHP's
    //     filter: on PHP 8.5.10 the filter already refuses 240.0.0.0/4,
    //     100.64.0.0/10, 2002::/16, most of ::/96 and every mapped address.
    //     Each refused entry gets an address inside it and, where the edge
    //     is not on a whole byte, the addresses just outside, so a wrong bit
    //     mask shows up as well as a missing line.
    // -------------------------------------------------------------------------
    echo "\n=== B2. SafeFetch's own range table, on its own ===\n";
    $ownTable = [
        // [address, expected answer, why]
        ['224.0.0.1', false, 'multicast 224.0.0.0/4'],
        ['239.255.255.255', false, 'multicast, top of the range'],
        ['223.255.255.255', true, 'just below 224.0.0.0/4'],
        ['240.0.0.1', false, 'class E 240.0.0.0/4'],
        ['255.255.255.254', false, 'class E, top of the range'],
        ['100.64.0.1', false, 'shared space 100.64.0.0/10'],
        ['100.127.255.255', false, 'shared space, top of the range'],
        ['100.63.255.255', true, 'just below 100.64.0.0/10'],
        ['100.128.0.0', true, 'just above 100.64.0.0/10'],
        ['8.8.8.8', true, 'an ordinary public IPv4 address'],
        ['fec0::1', false, 'site-local fec0::/10'],
        ['feff:ffff::1', false, 'site-local, top of the range'],
        ['febf:ffff::1', true, 'just below fec0::/10 (outside this table)'],
        ['ff02::1', false, 'multicast ff00::/8'],
        ['64:ff9b::a00:1', false, 'translation prefix 64:ff9b::/96'],
        ['64:ff9b::1:0:0', true, 'just outside 64:ff9b::/96 (only the 96th bit differs)'],
        ['64:ff9b:1::1', false, 'translation prefix 64:ff9b:1::/48'],
        ['2002:808:808::1', false, '6to4 2002::/16'],
        ['2003::1', true, 'just above 2002::/16'],
        ['::ffff:0:7f00:1', false, '"IPv4-translated" ::ffff:0:0:0/96 holding 127.0.0.1'],
        ['::ffff:0:808:808', false, '"IPv4-translated", refused even holding a public 8.8.8.8'],
        ['::7f00:1', false, '"IPv4-compatible" ::/96 holding 127.0.0.1'],
        ['::808:808', false, '"IPv4-compatible", refused even holding a public 8.8.8.8'],
        ['::1:0:0', true, 'just outside ::/96 (only the 96th bit differs; outside this table)'],
        ['::ffff:10.0.0.1', false, '"IPv4-mapped": the 10.0.0.1 inside fails the whole test'],
        ['::ffff:224.0.0.1', false, '"IPv4-mapped": the multicast address inside fails'],
        ['::ffff:8.8.8.8', true, '"IPv4-mapped": the 8.8.8.8 inside passes (PHP refuses it at the first line)'],
        ['2606:4700::1111', true, 'an ordinary public IPv6 address'],
    ];
    foreach ($ownTable as [$ip, $want, $why]) {
        $got = st_private('passesOwnRanges', [(string) inet_pton($ip)]);
        st_check('own table: ' . $ip . ' → ' . st_show($want) . ' (' . $why . ')', $got === $want, 'got ' . st_show($got));
    }

    // =========================================================================
    // C. check()
    // =========================================================================
    echo "\n=== C. check(): names and numbers, one message for every refusal ===\n";

    $mustRefuse = [
        'http://localhost/', 'http://printer.local/', 'http://[::1]/', 'http://[::ffff:10.0.0.1]/',
        'https://nonexistent.invalid/', 'http://a.localhost/', 'http://db.internal/', 'http://nas.home.arpa/',
        'http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::ffff:0:7f00:1]/',
        'http://１２７.０.０.１/', 'http://ｌｏｃａｌｈｏｓｔ/', 'ftp://example.org/', 'not an address',
    ];
    foreach ($mustRefuse as $url) {
        $c = SafeFetch::check($url);
        st_check(
            'check(' . st_show($url) . ') refused with the one message',
            $c['ok'] === false && $c['message'] === $R && $c['ips'] === [] && $c['port'] === 0,
            st_show($c)
        );
    }

    // A name that points at this machine. Only meaningful when the name
    // really does resolve to 127.0.0.1 here; otherwise it would be refused
    // for "not found", which proves nothing about this case.
    $lt = @dns_get_record('localtest.me', DNS_A);
    if (is_array($lt) === true && in_array('127.0.0.1', array_column($lt, 'ip'), true) === true) {
        $c = SafeFetch::check('http://localtest.me/');
        st_check('check("http://localtest.me/") — a name resolving to 127.0.0.1 — refused', $c['ok'] === false && $c['message'] === $R, st_show($c));
    } else {
        st_skip('check("http://localtest.me/") refused', 'localtest.me does not resolve to 127.0.0.1 here (no DNS?)');
    }

    // C2. A name with several answers. Refused when ANY answer is private,
    //     wherever it sits in the list; accepted only when every one is
    //     public. (Called directly: see "WHAT IT CANNOT PROVE".)
    foreach ([
        [['8.8.8.8', '10.0.0.1'], false, 'a private answer second'],
        [['10.0.0.1', '8.8.8.8'], false, 'a private answer first'],
        [['8.8.8.8', '1.1.1.1', '2606:4700::1111', '::1'], false, 'a private IPv6 answer last of four'],
        [['8.8.8.8', '1.1.1.1', '127.0.0.1', '2606:4700::1111'], false, 'a private answer in the middle'],
        [['8.8.8.8', 'garbage'], false, 'an answer that is not an address'],
        [[], false, 'no answer at all (name not found)'],
        [['8.8.8.8', '1.1.1.1', '2606:4700::1111'], true, 'control: every answer public'],
    ] as [$answers, $want, $why]) {
        $got = st_private('allPublic', [$answers]);
        st_check('every answer must be public: ' . json_encode($answers) . ' → ' . st_show($want) . ' (' . $why . ')', $got === $want, 'got ' . st_show($got));
    }

    // C3. Where a redirect leads, decided without a server (the test server
    //     in part D speaks only plain http, so it cannot send an https page
    //     that redirects down to http).
    $down = 'The address redirected from a secure (https) address to an insecure (http) one, which is not allowed.';
    foreach ([
        ['https://calendar.example/a/feed.ics', 'http://calendar.example/x', null, $down, 'https down to http refused'],
        ['https://calendar.example/a/feed.ics', 'HTTP://Calendar.Example/x', null, $down, 'https down to HTTP (capitals) refused'],
        ['https://calendar.example/a/feed.ics', '//other.example/x', 'https://other.example/x', '', 'scheme-relative keeps https'],
        ['https://calendar.example/a/feed.ics', 'webcal://other.example/b.ics', 'https://other.example/b.ics', '', 'webcal becomes https, so it is not a downgrade'],
        ['https://calendar.example/a/feed.ics', '../b.ics?k=1', 'https://calendar.example/b.ics?k=1', '', 'relative, resolved against the current address'],
        ['http://calendar.example/feed.ics', 'https://calendar.example/feed.ics', 'https://calendar.example/feed.ics', '', 'http up to https allowed'],
        ['http://calendar.example/feed.ics', 'http://other.example/', 'http://other.example/', '', 'http to http allowed'],
        ['https://calendar.example/feed.ics', 'ftp://other.example/x', null, $R, 'another scheme refused with the one message'],
        ['https://calendar.example/feed.ics', 'https://other.example/a b', null, $R, 'a space inside refused with the one message'],
        ['https://calendar.example/feed.ics', 'https://user@other.example/', null, $R, 'a user name refused with the one message'],
    ] as [$from, $location, $wantUrl, $wantMessage, $why]) {
        $hop = st_private('redirectHop', [st_private('parse', [$from]), $location]);
        $gotUrl = $hop['next'] === null ? null : $hop['next']['url'];
        st_check('redirect from ' . $from . ' to ' . st_show($location) . ': ' . $why, $gotUrl === $wantUrl && $hop['message'] === $wantMessage, st_show($hop));
    }

    $c = SafeFetch::check('http://1.1.1.1/');
    st_check('check("http://1.1.1.1/") accepted, pinned to 1.1.1.1, port 80', $c['ok'] === true && $c['ips'] === ['1.1.1.1'] && $c['port'] === 80, st_show($c));
    $c = SafeFetch::check('webcal://[2606:4700::1111]/x.ics');
    st_check('check("webcal://[2606:4700::1111]/x.ics") accepted, port 443', $c['ok'] === true && $c['ips'] === ['2606:4700::1111'] && $c['port'] === 443, st_show($c));

    // Whether to skip is decided WITHOUT SafeFetch. The first version asked
    // SafeFetch::check() and skipped when it refused, so a SafeFetch that
    // wrongly refused every real name still passed (P4's check, mutation
    // K3). Now: if the name resolves here, SafeFetch must accept it.
    $exampleDns = @dns_get_record('example.org', DNS_A | DNS_AAAA);
    if (is_array($exampleDns) === false || $exampleDns === []) {
        st_skip('check("https://example.org/") accepted', 'example.org does not resolve here (no DNS?)');
    } else {
        $pub = SafeFetch::check('https://example.org/');
        $allPublic = $pub['ok'] === true && $pub['ips'] !== [];
        foreach ($pub['ips'] as $ip) {
            $allPublic = $allPublic && SafeFetch::isPublicIp($ip);
        }
        st_check('check("https://example.org/") accepted, every address public (the name resolves here, so a refusal is a fault)', $allPublic, st_show($pub));
    }

    // The reserved names are refused BY NAME, not merely because they fail
    // to resolve here: even when the test override says they point at a
    // public address, they stay refused. (This also proves the override can
    // never re-open them.)
    foreach (['localhost', 'a.localhost', 'printer.local', 'db.internal', 'nas.home.arpa', 'home.arpa'] as $name) {
        SafeFetch::$testResolverOverride = [$name => ['ips' => ['8.8.8.8']]];
        $c = SafeFetch::check('http://' . $name . '/');
        SafeFetch::$testResolverOverride = null;
        st_check('"' . $name . '" is refused by name, even if it pointed at a public address', $c['ok'] === false && $c['message'] === $R, st_show($c));
    }
    // Control: an ordinary test name with the same override IS accepted, so
    // the lines above are not passing for some other reason.
    SafeFetch::$testResolverOverride = ['calendar.test' => ['ips' => ['8.8.8.8']]];
    $c = SafeFetch::check('http://calendar.test/');
    SafeFetch::$testResolverOverride = null;
    st_check('control: "calendar.test" with the same override is accepted', $c['ok'] === true && $c['ips'] === ['8.8.8.8'], st_show($c));

    // =========================================================================
    // D. get() against a local test server
    // =========================================================================
    echo "\n=== D. get() against a test server on this machine ===\n";

    $port    = (int) (getenv('SAFEFETCH_SELFTEST_PORT') !== false ? getenv('SAFEFETCH_SELFTEST_PORT') : 9053);
    $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safefetch-selftest-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $logFile = $workDir . DIRECTORY_SEPARATOR . 'requests.log';
    $router  = $workDir . DIRECTORY_SEPARATOR . 'router.php';
    $server  = null;

    // Always stop the server and remove the scratch folder, however the
    // script ends (a failure part way, or a fatal error).
    register_shutdown_function(static function () use (&$server, $workDir, $logFile, $router): void {
        if (is_resource($server) === true) {
            proc_terminate($server, 15);
            for ($i = 0; $i < 30; $i++) {
                $s = proc_get_status($server);
                if ($s['running'] === false) {
                    break;
                }
                usleep(100000);
            }
            proc_close($server);
        }
        foreach ([$logFile, $router] as $f) {
            if (is_file($f) === true) {
                unlink($f);
            }
        }
        if (is_dir($workDir) === true) {
            rmdir($workDir);
        }
    });

    $routerCode = <<<'ROUTER'
<?php
// Test server for tools/safefetch-selftest.php. Written to a scratch folder
// and deleted when the test ends. Pages are chosen by the last part of the path.
file_put_contents((string) getenv('SAFEFETCH_SELFTEST_LOG'), $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND | LOCK_EX);
$name = basename((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
switch ($name) {
    case 'ok':
        header('Content-Type: text/calendar');
        echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        break;
    case 'headers':
        header('Content-Type: application/json');
        echo json_encode(['ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'accept' => $_SERVER['HTTP_ACCEPT'] ?? '', 'host' => $_SERVER['HTTP_HOST'] ?? '']);
        break;
    case 'redirect':
        header('Location: ' . (string) ($_GET['to'] ?? ''), true, (int) ($_GET['code'] ?? 302));
        break;
    case 'nolocation':
        http_response_code(302);
        break;
    case 'chain':
        $n = (int) ($_GET['n'] ?? 0);
        if ($n > 0) {
            header('Location: chain?n=' . ($n - 1) . '&t=' . rawurlencode((string) ($_GET['t'] ?? '')), true, 302);
        } else {
            echo 'end of chain';
        }
        break;
    case 'big':
        header('Content-Type: text/plain');
        $mb = str_repeat('x', 1048576);
        for ($i = 0; $i < 6; $i++) {
            echo $mb;
        }
        break;
    case 'slow':
        sleep((int) ($_GET['s'] ?? 3));
        echo 'late';
        break;
    case 'status':
        http_response_code((int) ($_GET['c'] ?? 500));
        echo 'error page';
        break;
    default:
        http_response_code(404);
        echo 'no such test page';
}
ROUTER;

    $serverOk = false;
    if (@mkdir($workDir, 0700) === true && file_put_contents($router, $routerCode) !== false && touch($logFile) === true) {
        $busy = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($busy !== false) {
            fclose($busy);
            echo "Port {$port} is already in use; set SAFEFETCH_SELFTEST_PORT to a free port.\n";
        } else {
            $env = getenv();
            $env['SAFEFETCH_SELFTEST_LOG'] = $logFile;
            // One process on purpose: PHP's multi-worker mode leaves its
            // workers running when the main process is stopped.
            unset($env['PHP_CLI_SERVER_WORKERS']);
            $server = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                $workDir,
                $env
            );
            for ($i = 0; $i < 50 && is_resource($server) === true; $i++) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($sock !== false) {
                    fclose($sock);
                    $serverOk = true;
                    break;
                }
                usleep(100000);
            }
        }
    }
    st_check('test server started on 127.0.0.1:' . $port, $serverOk);

    if ($serverOk === true) {
        $base = 'http://calendar.test';
        SafeFetch::$testResolverOverride = ['calendar.test' => ['ips' => ['127.0.0.1'], 'port' => $port]];
        $requests = static function () use ($logFile): string {
            clearstatcache();
            return (string) file_get_contents($logFile);
        };
        /** Wait until the one-process test server has finished a slow page. */
        $waitUntilFree = static function () use ($port): void {
            for ($i = 0; $i < 40; $i++) {
                $h = curl_init('http://127.0.0.1:' . $port . '/ok?t=wait');
                curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
                curl_exec($h);
                if (curl_errno($h) === 0) {
                    return;
                }
                usleep(250000);
            }
        };
        $allMessages = [];
        $calAccept   = 'text/calendar, text/plain;q=0.5, */*;q=0.1';

        // D1. An ordinary download.
        $r = SafeFetch::get($base . '/ok', ['maxBytes' => 1048576, 'timeoutSeconds' => 5]);
        $allMessages[] = $r['message'];
        $want = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        st_check('get(/ok): ok, status 200, the body, its size', $r['ok'] === true && $r['status'] === 200 && $r['body'] === $want && $r['bytes'] === strlen($want) && $r['capped'] === false && $r['message'] === '', st_show($r));

        // D1b. Why part A refuses the shortened numeric spellings: plain
        //      curl, given them, really does reach this machine. (A control
        //      for part A: if curl did not read them as 127.0.0.1, refusing
        //      them would be tidiness rather than protection.)
        foreach (['127.1', '0x7f.1', '2130706433', '0177.0.0.1'] as $n => $spelling) {
            $h = curl_init('http://' . $spelling . ':' . $port . '/ok?t=shorthand' . $n);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
            curl_exec($h);
            st_check('control: plain curl reads "' . $spelling . '" as this machine and reaches the test server', curl_errno($h) === 0 && str_contains($requests(), 't=shorthand' . $n . "\n") === true, 'curl errno ' . curl_errno($h));
            st_check('and SafeFetch refuses "http://' . $spelling . '/"', SafeFetch::normaliseUrl('http://' . $spelling . '/') === null && SafeFetch::check('http://' . $spelling . '/')['ok'] === false);
        }

        // D2. What the other server sees.
        $r = SafeFetch::get($base . '/headers', ['accept' => $calAccept]);
        $seen = json_decode($r['body'], true);
        $ua   = is_array($seen) === true ? (string) $seen['ua'] : '';
        st_check('User-Agent is "' . Site::productName() . ' calendar import", with no web address', $ua === Site::productName() . ' calendar import' && str_contains($ua, '://') === false && str_contains($ua, 'calendar.test') === false, st_show($seen));
        st_check('Accept header is the one asked for', is_array($seen) === true && $seen['accept'] === $calAccept, st_show($seen));
        st_check('Host header is "calendar.test" (the address kept port 80; only the connection went to the test port)', is_array($seen) === true && $seen['host'] === 'calendar.test', st_show($seen));

        // D3. A proxy set in the environment is ignored.
        putenv('http_proxy=http://127.0.0.1:9');
        putenv('all_proxy=http://127.0.0.1:9');
        $r = SafeFetch::get($base . '/ok?t=proxy-ignored', []);
        $ctl = curl_init($base . '/ok?t=proxy-control');
        curl_setopt_array($ctl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_RESOLVE        => ['calendar.test:' . $port . ':127.0.0.1'],
            CURLOPT_CONNECT_TO     => ['calendar.test:80:calendar.test:' . $port],
        ]);
        curl_exec($ctl);
        $ctlErr = curl_errno($ctl);
        putenv('http_proxy');
        putenv('all_proxy');
        st_check('with http_proxy/all_proxy set to a dead proxy, get() still downloads directly', $r['ok'] === true, st_show($r));
        st_check('control: plain curl with the same environment goes to the proxy and fails', $ctlErr !== 0 && str_contains($requests(), 'proxy-control') === false, 'curl errno ' . $ctlErr);

        // D4. Redirects to places that must not be reached. The first one
        //     points at the test server itself, so if it were followed the
        //     request would show up in the server's log.
        $badTargets = [
            'http://127.0.0.1:' . $port . '/SHOULD-NOT-ARRIVE-1',
            'http://127.0.0.1/SHOULD-NOT-ARRIVE-2',
            'http://localhost/SHOULD-NOT-ARRIVE-3',
            'http://[::1]/SHOULD-NOT-ARRIVE-4',
            'http://[::ffff:0:7f00:1]/SHOULD-NOT-ARRIVE-5',
            'http://calendar.test:' . $port . '/SHOULD-NOT-ARRIVE-6',
            'ftp://calendar.test/SHOULD-NOT-ARRIVE-7',
            'http://169.254.169.254/SHOULD-NOT-ARRIVE-8',
        ];
        foreach ($badTargets as $target) {
            $r = SafeFetch::get($base . '/redirect?to=' . rawurlencode($target), []);
            $allMessages[] = $r['message'];
            st_check('redirect to ' . $target . ' refused at hop 2 with the one message', $r['ok'] === false && $r['message'] === $R && $r['status'] === 302, st_show($r));
        }
        // (The first request of each test carries the target in its own query
        // string, so only a request whose PATH is the target counts.)
        st_check('none of those redirect targets ever reached the test server', preg_match('~^/SHOULD-NOT-ARRIVE~m', $requests()) === 0);

        // D5. Relative and scheme-relative redirects are followed, and every
        //     redirect status works.
        foreach ([
            '/redirect?to=' . rawurlencode('ok')                         => 'relative "ok"',
            '/a/b/redirect?to=' . rawurlencode('../ok')                  => 'relative "../ok"',
            '/redirect?to=' . rawurlencode('/x/ok')                      => 'absolute path "/x/ok"',
            '/redirect?to=' . rawurlencode('//calendar.test/ok')         => 'scheme-relative "//calendar.test/ok"',
            '/redirect?code=301&to=' . rawurlencode('ok')                => 'status 301',
            '/redirect?code=303&to=' . rawurlencode('ok')                => 'status 303',
            '/redirect?code=307&to=' . rawurlencode('ok')                => 'status 307',
            '/redirect?code=308&to=' . rawurlencode('ok')                => 'status 308',
        ] as $path => $what) {
            $r = SafeFetch::get($base . $path, []);
            st_check('redirect followed: ' . $what, $r['ok'] === true && $r['body'] === $want, st_show($r));
        }
        $r = SafeFetch::get($base . '/a/b/redirect?to=' . rawurlencode('../../../../ok?t=dots'), []);
        st_check('".." past the top of the path stays on the same host', $r['ok'] === true && str_contains($requests(), "/ok?t=dots\n") === true, st_show($r));

        // D6. Too many redirects. The default allows three.
        $r = SafeFetch::get($base . '/chain?n=3&t=three', []);
        $count3 = substr_count($requests(), 't=three');
        st_check('three redirects are followed (4 requests)', $r['ok'] === true && $r['body'] === 'end of chain' && $count3 === 4, st_show($r) . ' requests=' . $count3);
        $r = SafeFetch::get($base . '/chain?n=4&t=four', []);
        $allMessages[] = $r['message'];
        $count4 = substr_count($requests(), 't=four');
        st_check('four redirects are refused, and the fifth address is never requested', $r['ok'] === false && $r['message'] === 'The address redirected too many times.' && $count4 === 4, st_show($r) . ' requests=' . $count4);
        $r = SafeFetch::get($base . '/redirect?t=zero&to=ok', ['maxRedirects' => 0]);
        st_check('maxRedirects 0 refuses the first redirect', $r['ok'] === false && $r['message'] === 'The address redirected too many times.', st_show($r));

        // D7. The size cap (a 6 MB body).
        $r = SafeFetch::get($base . '/big', ['maxBytes' => 5 * 1048576]);
        $allMessages[] = $r['message'];
        st_check('6 MB body with a 5 MB cap: capped, not ok, no body', $r['ok'] === false && $r['capped'] === true && $r['body'] === '' && $r['bytes'] > 5 * 1048576, st_show(['ok' => $r['ok'], 'capped' => $r['capped'], 'bytes' => $r['bytes'], 'message' => $r['message']]));
        $r = SafeFetch::get($base . '/big', ['maxBytes' => 7 * 1048576]);
        st_check('control: the same body with a 7 MB cap is read in full', $r['ok'] === true && $r['capped'] === false && $r['bytes'] === 6 * 1048576 && strlen($r['body']) === 6 * 1048576);

        // D8. Error answers.
        foreach ([404, 500] as $code) {
            $r = SafeFetch::get($base . '/status?c=' . $code, []);
            $allMessages[] = $r['message'];
            st_check('HTTP ' . $code . ': not ok, status kept, message says ' . $code, $r['ok'] === false && $r['status'] === $code && $r['body'] === '' && str_contains($r['message'], (string) $code), st_show($r));
        }
        $r = SafeFetch::get($base . '/nolocation', []);
        st_check('a redirect with no Location is not ok', $r['ok'] === false && $r['status'] === 302, st_show($r));

        // D9. https only.
        $r = SafeFetch::get($base . '/ok?t=https-only', ['httpsOnly' => true]);
        st_check('httpsOnly refuses a plain http address before any request', $r['ok'] === false && $r['message'] === 'Only secure (https) addresses can be used here.' && str_contains($requests(), 't=https-only') === false, st_show($r));

        // D10. Option mistakes throw rather than fall back to a default.
        foreach ([
            ['maxbytes' => 10], ['maxBytes' => 0], ['accept' => "text/calendar\r\nX-Evil: 1"], ['maxRedirects' => 11], ['deadline' => 'soon'],
        ] as $bad) {
            $threw = false;
            try {
                SafeFetch::get($base . '/ok', $bad);
            } catch (InvalidArgumentException $e) {
                $threw = true;
            }
            st_check('get() refuses the option ' . json_encode($bad), $threw);
        }

        // D11. A deadline already past: nothing is requested.
        $r = SafeFetch::get($base . '/ok?t=past-deadline', ['deadline' => microtime(true) - 1.0]);
        $allMessages[] = $r['message'];
        st_check('a deadline already past fails before any request', $r['ok'] === false && $r['message'] === 'The other server took too long to answer.' && str_contains($requests(), 't=past-deadline') === false, st_show($r));

        // D12. A slow answer past timeoutSeconds = 2.
        $t0 = microtime(true);
        $r  = SafeFetch::get($base . '/slow?s=4&t=timeout', ['timeoutSeconds' => 2]);
        $dt = microtime(true) - $t0;
        st_check(sprintf('a 4-second answer with timeoutSeconds 2 fails after about 2 s (%.2f s)', $dt), $r['ok'] === false && $r['message'] === 'The other server took too long to answer.' && $dt >= 1.8 && $dt < 3.5, st_show($r));
        $waitUntilFree();

        // D13. A deadline 1 second away beats a 10-second time limit.
        $t0 = microtime(true);
        $r  = SafeFetch::get($base . '/slow?s=4&t=deadline', ['timeoutSeconds' => 10, 'deadline' => microtime(true) + 1.0]);
        $dt = microtime(true) - $t0;
        st_check(sprintf('a deadline 1 s away fails after about 1 s, long before the 10 s limit (%.2f s)', $dt), $r['ok'] === false && $r['message'] === 'The other server took too long to answer.' && $dt >= 0.8 && $dt < 2.5, st_show($r));
        $waitUntilFree();

        // D14. Nothing logged or returned holds the address.
        Logger::$records = [];
        $r = SafeFetch::get($base . '/private-SECRETTOKEN123/basic.ics?key=SECRETKEY456', []);
        $allMessages[] = $r['message'];
        $logged = implode("\n", Logger::$records);
        st_check('a failed fetch is logged with the host and the status', count(Logger::$records) === 1 && str_contains($logged, 'calendar.test') === true && str_contains($logged, '404') === true, $logged);
        st_check('the log holds neither the path nor the query', str_contains($logged, 'SECRET') === false && str_contains($logged, '/private') === false && str_contains($logged, 'key=') === false, $logged);
        $leaky = array_filter($allMessages, static fn (string $m): bool => str_contains($m, 'calendar.test') || str_contains($m, 'SECRET') || str_contains($m, 'SHOULD-NOT') || str_contains($m, '127.0.0.1'));
        st_check('no message handed back contains an address (' . count($allMessages) . ' messages)', $leaky === [], st_show($leaky));

        SafeFetch::$testResolverOverride = null;

        // =====================================================================
        // E. The pre-request check, on SafeFetch's own curl handle
        // =====================================================================
        echo "\n=== E. The pre-request check on SafeFetch's own handle ===\n";
        if (defined('CURLOPT_PREREQFUNCTION') === false) {
            st_skip('pre-request check', 'this libcurl is older than 7.80, so the option does not exist');
        } else {
            $o = st_private('options', [[]]);
            // The handle is built for the approved address 192.0.2.1 (a
            // documentation address nobody uses). The test then points the
            // connection somewhere else, as a resolver or parser that
            // disagreed with SafeFetch would. The check must stop it before
            // any request is sent.
            $state  = st_private('newHopState', []);
            $handle = st_private('buildHandle', ['http://calendar.test/ok?t=prereq-refuse', 'calendar.test', 80, ['192.0.2.1'], $o, 5000, $state]);
            curl_setopt($handle, CURLOPT_CONNECT_TO, ['calendar.test:80:127.0.0.1:' . $port]);
            curl_exec($handle);
            $err = curl_errno($handle);
            st_check('connection to an address that was not approved is stopped (curl error 42)', $err === 42 && $state->prereqRefused === true, 'curl errno ' . $err);
            st_check('and the request never reached the server', str_contains($requests(), 'prereq-refuse') === false);

            $state  = st_private('newHopState', []);
            $handle = st_private('buildHandle', ['http://calendar.test/ok?t=prereq-allow', 'calendar.test', 80, ['127.0.0.1'], $o, 5000, $state]);
            curl_setopt($handle, CURLOPT_CONNECT_TO, ['calendar.test:80:127.0.0.1:' . $port]);
            curl_exec($handle);
            $err = curl_errno($handle);
            st_check('control: the same handle approved for 127.0.0.1 connects and downloads', $err === 0 && $state->status === 200 && $state->prereqRefused === false && str_contains($requests(), 'prereq-allow') === true, 'curl errno ' . $err);
        }
    } else {
        st_check('parts D and E need the test server', false, 'it did not start');
    }

    // =========================================================================
    // F. Pinning against the real internet
    // =========================================================================
    echo "\n=== F. Pinning on a real public address (example address) ===\n";
    $real = 'https://www.gov.uk/bank-holidays/england-and-wales.ics';
    // As in part C, whether to skip is decided WITHOUT SafeFetch (mutation
    // K3): if the name resolves here, SafeFetch refusing it is a FAIL.
    $govDns = @dns_get_record('www.gov.uk', DNS_A | DNS_AAAA);
    $c      = SafeFetch::check($real);
    if (is_array($govDns) === false || $govDns === []) {
        st_skip('pinning on a real address', 'www.gov.uk does not resolve here (no DNS?)');
    } elseif ($c['ok'] === false) {
        st_check('check() accepts www.gov.uk, which resolves here', false, st_show($c));
    } else {
        $o      = st_private('options', [['timeoutSeconds' => 15, 'maxBytes' => 2097152]]);
        $state  = st_private('newHopState', []);
        $handle = st_private('buildHandle', [(string) SafeFetch::normaliseUrl($real), $c['host'], $c['port'], $c['ips'], $o, 15000, $state]);
        curl_exec($handle);
        $err     = curl_errno($handle);
        $primary = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        if ($err !== 0 && $primary === '') {
            // Whether this is a fair skip is decided WITHOUT SafeFetch: if
            // plain curl reaches the same address at this moment, then the
            // fault is SafeFetch's and skipping would hide it (round-2
            // check, mutation K21: the pins joined by ";" instead of ",").
            if (st_reachable($real) === true) {
                st_check('SafeFetch connects to a real address that plain curl reaches at the same moment', false, 'curl errno ' . $err . ' from SafeFetch\'s own handle');
            } else {
                st_skip('pinning on a real address', 'no outbound network here: plain curl cannot reach it either (SafeFetch errno ' . $err . ')');
            }
        } else {
            $pb = @inet_pton($primary);
            $inList = false;
            foreach ($c['ips'] as $ip) {
                $inList = $inList || ($pb !== false && inet_pton($ip) === $pb);
            }
            st_check('the connection went to one of the checked addresses (' . $primary . ' in ' . implode(', ', $c['ips']) . ')', $inList);

            // A pinned address beats the name server: pin the same name to a
            // different public address and watch curl connect THERE. Plain
            // http on port 80 is used for this one step, because over https
            // the certificate would not match and curl then reports no
            // address at all. The other server's answer does not matter.
            $plain  = 'http://www.gov.uk/';
            $cp     = SafeFetch::check($plain);
            $state  = st_private('newHopState', []);
            $handle = st_private('buildHandle', [$plain, $cp['host'], $cp['port'], ['1.1.1.1'], $o, 10000, $state]);
            curl_exec($handle);
            $pinnedTo = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
            if ($cp['ok'] === false) {
                // The name resolved above, so SafeFetch refusing it is a fault (mutation K3).
                st_check('check() accepts http://www.gov.uk/, which resolves here', false, st_show($cp));
            } elseif ($pinnedTo === '') {
                // Same rule as above: a skip only when plain curl cannot
                // reach 1.1.1.1 either (round-2 check, mutation K21).
                if (st_reachable('http://1.1.1.1/') === true) {
                    st_check('SafeFetch connects to 1.1.1.1, which plain curl reaches at the same moment', false, 'curl errno ' . curl_errno($handle) . ' from SafeFetch\'s own handle');
                } else {
                    st_skip('a pinned address beats the name server', 'no outbound network here: plain curl cannot reach 1.1.1.1 either (SafeFetch errno ' . curl_errno($handle) . ')');
                }
            } else {
                st_check('a pinned address beats the name server (www.gov.uk pinned to 1.1.1.1; connected to ' . $pinnedTo . ')', $pinnedTo === '1.1.1.1' && in_array('1.1.1.1', $cp['ips'], true) === false, st_show($cp['ips']));
            }

            $r = SafeFetch::get($real, ['timeoutSeconds' => 15, 'maxBytes' => 2097152, 'accept' => 'text/calendar, text/plain;q=0.5, */*;q=0.1']);
            st_check('get() on the real address: ok, 200 (this proves the connection and the download only, NOT reading a calendar)', $r['ok'] === true && $r['status'] === 200 && str_starts_with($r['body'], 'BEGIN:VCALENDAR') === true, st_show(['ok' => $r['ok'], 'status' => $r['status'], 'message' => $r['message'], 'bytes' => $r['bytes']]));
        }
    }

    // =========================================================================
    // G. Nothing under web/ sets the test-only override
    // =========================================================================
    echo "\n=== G. The test override is never set by the portal ===\n";
    $webDir   = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web';
    $mentions = [];
    $files    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webDir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() === true && str_ends_with($file->getFilename(), '.php') === true
            && str_contains((string) file_get_contents($file->getPathname()), 'testResolverOverride') === true
        ) {
            $mentions[] = substr($file->getPathname(), strlen($webDir) + 1);
        }
    }
    $expected = '_core' . DIRECTORY_SEPARATOR . 'SafeFetch.php';
    st_check('only SafeFetch.php mentions testResolverOverride under web/', $mentions === [$expected], st_show($mentions));
    $own = (string) file_get_contents($webDir . DIRECTORY_SEPARATOR . $expected);
    st_check(
        'SafeFetch.php assigns it once, in its own declaration, to null',
        preg_match_all('/\$testResolverOverride\s*=(?!=)/', $own) === 1 && preg_match('/public static \?array \$testResolverOverride = null;/', $own) === 1
    );

    // =========================================================================
    // H. Hymnal uses the shared test
    // =========================================================================
    echo "\n=== H. Hymnal refuses what SafeFetch refuses ===\n";
    foreach ([
        ['https://100.64.0.1/', '100.64.0.1'],
        ['https://224.0.0.1/', '224.0.0.1'],
        ['https://192.0.2.1/', '192.0.2.1'],
        ['https://[::ffff:0:7f00:1]/', '[::ffff:0:7f00:1]'],
        ['https://[::7f00:1]/', '[::7f00:1]'],
        ['https://[fec0::1]/', '[fec0::1]'],
        ['https://[64:ff9b::a00:1]/', '[64:ff9b::a00:1]'],
    ] as [$baseUrl, $host]) {
        st_check('Hymnal refuses ' . $baseUrl, Hymnal::validateRemoteConfig($baseUrl, $host) !== '');
    }
    st_check('Hymnal still accepts a public address (https://8.8.8.8/)', Hymnal::validateRemoteConfig('https://8.8.8.8/', '8.8.8.8') === '');
    st_check('Hymnal keeps its own rule: a name that does not resolve is not refused', Hymnal::validateRemoteConfig('https://nonexistent.invalid/', 'nonexistent.invalid') === '');
    if (is_array($lt) === true && in_array('127.0.0.1', array_column($lt, 'ip'), true) === true) {
        st_check('Hymnal refuses a name resolving to 127.0.0.1 (localtest.me)', Hymnal::validateRemoteConfig('https://localtest.me/', 'localtest.me') !== '');
    } else {
        st_skip('Hymnal refuses localtest.me', 'localtest.me does not resolve to 127.0.0.1 here');
    }
    // Hymnal's NAME branch on a range its old flags let through. 127.0.0.1
    // (above) proves nothing here, because the old flags refused it too.
    // nip.io is a public service whose names answer with the address they
    // spell; the test first checks it really does, and prints SKIPPED if not.
    $nip = @dns_get_record('100.64.0.1.nip.io', DNS_A);
    if (is_array($nip) === true && array_column($nip, 'ip') === ['100.64.0.1']) {
        st_check('Hymnal refuses a name resolving to 100.64.0.1 (100.64.0.1.nip.io), which its old flags allowed', Hymnal::validateRemoteConfig('https://100.64.0.1.nip.io/', '100.64.0.1.nip.io') !== '');
        st_check('and SafeFetch::check() refuses it too', SafeFetch::check('https://100.64.0.1.nip.io/')['ok'] === false);
    } else {
        st_skip('Hymnal refuses 100.64.0.1.nip.io', 'the name does not resolve to exactly 100.64.0.1 here (no DNS?)');
    }

    // =========================================================================
    // I. The certificate checks, against a local https server
    // =========================================================================
    // WHY (P4's check, 22 September 2026): SafeFetch sets
    // CURLOPT_SSL_VERIFYPEER (is the certificate from someone we trust?) and
    // CURLOPT_SSL_VERIFYHOST (is it for the name we asked for?), and nothing
    // tested either: switching off either one (mutations K1, K2) left every
    // other check passing. This part tests them one at a time:
    //   I1. The certificate is NOT trusted, so get() must refuse it.
    //       Control: plain curl with checking switched off downloads it,
    //       which proves the server works and the refusal is the check's.
    //   I2. The same certificate IS trusted for this one handle
    //       (CURLOPT_CAINFO) but asked for under a different name, so the
    //       handle SafeFetch builds must refuse it. Control: under the
    //       certificate's own name the same handle succeeds.
    // HOW THE SERVER RUNS: openssl s_server otherwise reads the keyboard and
    // never ends by itself. An earlier attempt at this test hung exactly
    // that way. So it is started detached, with no keyboard input, and it is
    // always stopped by the shutdown function below, however the script
    // ends. Every request carries a time limit.
    echo "\n=== I. Certificate checks against a local https server ===\n";
    $openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
    if ($openssl === '') {
        st_skip('certificate checks', 'the openssl program is not installed here, so the certificate settings are NOT tested');
    } else {
        $tlsPort  = (int) (getenv('SAFEFETCH_SELFTEST_TLS_PORT') !== false ? getenv('SAFEFETCH_SELFTEST_TLS_PORT') : 9054);
        $tlsDir   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'safefetch-tls-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $certFile = $tlsDir . DIRECTORY_SEPARATOR . 'cert.pem';
        $keyFile  = $tlsDir . DIRECTORY_SEPARATOR . 'key.pem';
        $tls      = null;
        register_shutdown_function(static function () use (&$tls, $tlsDir, $certFile, $keyFile): void {
            if (is_resource($tls) === true) {
                proc_terminate($tls, 15);
                for ($i = 0; $i < 30; $i++) {
                    if (proc_get_status($tls)['running'] === false) {
                        break;
                    }
                    usleep(100000);
                }
                proc_close($tls);
            }
            foreach ([$certFile, $keyFile] as $file) {
                if (is_file($file) === true) {
                    unlink($file);
                }
            }
            if (is_dir($tlsDir) === true) {
                rmdir($tlsDir);
            }
        });
        $quiet = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

        // A certificate for calendar.test, valid for one day, made on the spot.
        $made = false;
        if (@mkdir($tlsDir, 0700) === true) {
            $gen = proc_open(
                [$openssl, 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $keyFile, '-out', $certFile,
                 '-days', '1', '-subj', '/CN=calendar.test', '-addext', 'subjectAltName=DNS:calendar.test'],
                $quiet,
                $genPipes
            );
            if (is_resource($gen) === true) {
                for ($i = 0; $i < 300 && proc_get_status($gen)['running'] === true; $i++) {
                    usleep(100000); // up to 30 seconds
                }
                if (proc_get_status($gen)['running'] === true) {
                    proc_terminate($gen, 9);
                }
                proc_close($gen);
            }
            $made = is_file($certFile) === true && is_file($keyFile) === true;
        }

        $tlsOk = false;
        if ($made === false) {
            st_skip('certificate checks', 'openssl could not make a test certificate here');
        } elseif (($busy = @fsockopen('127.0.0.1', $tlsPort, $errno, $errstr, 0.5)) !== false) {
            fclose($busy);
            st_check('the certificate test port ' . $tlsPort . ' is free (set SAFEFETCH_SELFTEST_TLS_PORT to another)', false);
        } else {
            // ONLY "127.0.0.1:port", never the port on its own. OpenSSL
            // takes this form and listens on this machine alone. LibreSSL,
            // which is what /usr/bin/openssl is on macOS, refuses it
            // ("getservbyname failure") and wants the bare port — but with
            // the bare port openssl listens on EVERY network interface
            // (measured with lsof in the round-3 check: "TCP *:port"), which
            // would put a self-signed https server on the local network for
            // a few seconds. A test is not worth that, and on LibreSSL the
            // server cannot serve a page anyway (curl errno 56), so there is
            // nothing to gain: where this spelling is refused, part I is
            // SKIPPED with the reason.
            $tls = proc_open(
                [$openssl, 's_server', '-accept', '127.0.0.1:' . $tlsPort, '-cert', $certFile, '-key', $keyFile, '-www', '-quiet'],
                $quiet,
                $tlsPipes,
                $tlsDir
            );
            // Stop waiting as soon as the port answers OR openssl gives up:
            // is_resource() stays true after the process has exited, so the
            // round-3 check saw five seconds wasted on LibreSSL.
            for ($i = 0; $i < 50 && is_resource($tls) === true; $i++) {
                $sock = @fsockopen('127.0.0.1', $tlsPort, $errno, $errstr, 0.2);
                if ($sock !== false) {
                    fclose($sock);
                    $tlsOk = true;
                    break;
                }
                if (proc_get_status($tls)['running'] === false) {
                    break;
                }
                usleep(100000);
            }
            if ($tlsOk === false) {
                if (is_resource($tls) === true) {
                    proc_terminate($tls, 15);
                    proc_close($tls);
                    $tls = null;
                }
                $version = trim((string) shell_exec(escapeshellarg($openssl) . ' version 2>/dev/null'));
                st_skip('certificate checks', 'this openssl will not listen on 127.0.0.1:' . $tlsPort . ' (' . ($version !== '' ? $version : 'unknown openssl') . '); the bare-port form it wants would listen on every network interface, which this test will not do, so the certificate settings are NOT tested here');
            }
        }

        if ($tlsOk === true) {
            // The control comes FIRST and decides whether this machine can
            // run part I at all: plain curl, with checking switched off,
            // must be able to download from the test server. Where it
            // cannot, the server is not usable here and the part is SKIPPED
            // with the reason — never failed. (Seen in round 2's FIX cycle,
            // on macOS's own LibreSSL 3.3.6, while this test still fell back
            // to the bare-port form: the server started but every connection
            // ended with curl errno 56, so the checks would "pass" for the
            // wrong reason while the controls failed. LibreSSL no longer
            // reaches this point — it stops at "will not listen on
            // 127.0.0.1" — but a different openssl still could.) Where the control works, a broken
            // certificate setting DOES fail the test: proven with OpenSSL
            // 3.6.4 by switching each setting off in turn.
            $raw = curl_init('https://127.0.0.1:' . $tlsPort . '/');
            curl_setopt_array($raw, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
            ]);
            $rawBody  = curl_exec($raw);
            $rawErrno = curl_errno($raw);
            if (is_string($rawBody) === false || $rawBody === '') {
                $version = trim((string) shell_exec(escapeshellarg($openssl) . ' version 2>/dev/null'));
                st_skip('certificate checks', 'the test server on this machine will not serve a page even with checking switched off (curl errno ' . $rawErrno . '; ' . ($version !== '' ? $version : 'unknown openssl') . '), so the certificate settings are NOT tested here');
                $tlsOk = false;
            } else {
                st_check('I1 control: with checking switched off, plain curl downloads the test page', true);
            }
        }

        if ($tlsOk === true) {
            // I1: SafeFetch refuses the untrusted certificate.
            SafeFetch::$testResolverOverride = ['calendar.test' => ['ips' => ['127.0.0.1'], 'port' => $tlsPort]];
            $r = SafeFetch::get('https://calendar.test/', ['timeoutSeconds' => 10]);
            st_check('I1 peer check: get() refuses a certificate nobody trusts', $r['ok'] === false && $r['body'] === '', st_show(['ok' => $r['ok'], 'status' => $r['status'], 'message' => $r['message'], 'bytes' => $r['bytes']]));

            // I2: the certificate trusted for this one handle; the name decides.
            $o = st_private('options', [['timeoutSeconds' => 10]]);
            foreach ([['calendar.test', true], ['other.test', false]] as [$name, $wantOk]) {
                SafeFetch::$testResolverOverride = [$name => ['ips' => ['127.0.0.1'], 'port' => $tlsPort]];
                $state  = st_private('newHopState', []);
                $handle = st_private('buildHandle', ['https://' . $name . '/', $name, 443, ['127.0.0.1'], $o, 10000, $state]);
                if ($handle === null) {
                    st_check('I2 SafeFetch built a handle for ' . $name, false);
                    continue;
                }
                curl_setopt($handle, CURLOPT_CAINFO, $certFile);
                curl_exec($handle);
                $errno = curl_errno($handle);
                if ($wantOk === true) {
                    st_check('I2 control: with the certificate trusted, its own name (calendar.test) connects', $errno === 0 && $state->status === 200, 'curl errno ' . $errno . ', status ' . $state->status);
                    // If this control fails, the I2 result below means nothing:
                    // it would "pass" because the connection failed anyway.
                    if ($errno !== 0) {
                        st_skip('I2 name check', 'the control above failed, so a refusal here would prove nothing on this machine');
                        break;
                    }
                } else {
                    st_check('I2 name check: a trusted certificate for calendar.test is refused when asked for as other.test', $errno !== 0 && $state->status === 0, 'curl errno ' . $errno . ', status ' . $state->status);
                }
            }
            SafeFetch::$testResolverOverride = null;
        }
    }

    // =========================================================================
    // Summary
    // =========================================================================
    echo "\n";
    st_check('no PHP warning or deprecation was raised while testing', $GLOBALS['st_php_warnings'] === [], implode('; ', $GLOBALS['st_php_warnings']));
    echo sprintf("\n%d passed, %d failed, %d skipped.\n", $GLOBALS['st_pass'], $GLOBALS['st_fail'], $GLOBALS['st_skip']);
    exit($GLOBALS['st_fail'] === 0 ? 0 : 1);
}
