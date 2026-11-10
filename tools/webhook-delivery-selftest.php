<?php
// Path: tools/webhook-delivery-selftest.php
/**
 * -----------------------------------------------------------------------------
 * Where an outbound webhook may go, and what is kept of it 🪝🛡️ (#615)
 * -----------------------------------------------------------------------------
 * WHAT WAS WRONG. An administrator could save any `http://` or `https://`
 * address as a webhook's target — the save checked only that it LOOKED like
 * an address — and every delivery was posted to it with plain curl. So a
 * webhook could be pointed at this machine, at the hosting company's own
 * network, or at the address cloud servers hand their own passwords out from;
 * nothing pinned the connection to a checked address (nothing was checked);
 * and the first 500 characters of whatever answered were stored on the
 * delivery row, together with curl's own error text, which names the host.
 *
 * WHAT THIS PROVES, and how (every part against a REAL database):
 *   A. `SafeFetch::post()` on its own, in a child process: a send through the
 *      pin reaches a receiver with the body and the headers exactly as given
 *      and NO `Accept-Encoding` (#716); private, internal, reserved and
 *      oddly-spelled addresses are refused with the word `refused` before any
 *      connection; the pre-request check stops a connection that ends up
 *      somewhere other than the checked address (the DNS-rebinding case,
 *      faked exactly as `safefetch-selftest.php` part E fakes it); a redirect
 *      is not followed; an oversized reply is cut off; a slow one times out;
 *      and no message or error row names the address.
 *   B. `WebhookDispatcher`, in the same child process, through `retryDue()`
 *      (which shares `attemptDelivery()` with `emit()`): a webhook saved
 *      BEFORE #615 that points at this machine is refused at delivery and the
 *      receiver behind it hears nothing; a public-looking name is delivered
 *      THROUGH THE PIN (the name resolves nowhere but the checked answer),
 *      with the body and signature exactly as before; a name that was fine
 *      and is then RE-POINTED at a private address is refused on the next
 *      delivery; an error reply is recorded as a word, never as the reply;
 *      dead-lettering still happens; and no delivery row, error row,
 *      activity row or PHP error-log line holds the address.
 *   C. The admin page over real HTTP: saving a webhook aimed at 127.0.0.1,
 *      localhost, 169.254.169.254, a 10.x address, [::1], the decimal,
 *      octal and hexadecimal spellings of 127.0.0.1, an unusual port, and a
 *      NAME that resolves to a private address is refused, each with no row
 *      written; a public-looking address is saved, in SafeFetch's standard
 *      spelling; PAUSING a webhook saved before #615 is never refused while
 *      switching it back on is; the refusal is recorded in the activity log
 *      with no part of the address; and the deliveries list shows a reason
 *      word and never a stored reply.
 *   D-102 (the owner, 10 November 2026): which port numbers a webhook may
 *      use is an INSTALLATION setting, standard ports by default. A9 and
 *      B7 prove any port on a public address goes through when — and only
 *      when — the installation's own row allows it (B7b and C6f: an
 *      organisation's row saying yes changes nothing; C6: an ordinary
 *      administrator cannot set it; C6d: a private address stays refused on
 *      any port), A9e that the calendar fetcher's rule is unchanged, and A9
 *      that the connection goes through post()'s OWN pin entry (the test's
 *      name-to-port entry is not given, so it cannot carry the connection).
 *      A2a, B1e and C5b: an address WRITTEN in a way that is not allowed (a
 *      port, a user name and password, webcal) is `not-allowed` with its own
 *      true sentence, never `refused`. B8: the 1-30 second time limit.
 *   P. Plants. Each guard is taken out, in a COPY, and the run must go red
 *      on the check meant to catch it; then the copy is put back and must be
 *      green again. One plant puts the ORIGINAL pre-#615 send code back into
 *      the dispatcher, so "this fails on the old code" is proved on every
 *      run, not just once.
 *
 * WHAT IT CANNOT PROVE, said plainly:
 *   - A name resolving to a private address is tried through the public
 *     nip.io service (`10.0.0.1.nip.io` answers 10.0.0.1). Where this machine
 *     cannot resolve it, the check still runs and the address is still
 *     refused — but because the name was not FOUND, not because it led
 *     somewhere private; the check's own label says which happened.
 *   - The re-pointing in B3 is a real name (`127.0.0.1.nip.io`) whose CHECKED
 *     answer is first supplied by `SafeFetch::$testResolverOverride` and then
 *     by the real name server. No test here can make a real name server
 *     change its answer mid-request; part A's pre-request case is what covers
 *     the connection itself going somewhere unchecked.
 *   - `emit()` is not driven (it needs an event inside a signed-in request);
 *     it calls the same private `attemptDelivery()` that `retryDue()` does.
 *   - Nothing here reaches a real outside receiver over https.
 *
 * Usage:  SELFTEST_DB_NAME=selftest_webhookdelivery SELFTEST_DB_PASS=… SELFTEST_DB_PORT=… \
 *         [SELFTEST_WEB_PORT=10930] [WEBHOOK_SELFTEST_RECEIVER_PORT=10931] \
 *         php tools/webhook-delivery-selftest.php
 *
 *         The database must already hold `web/_sql/full_schema.sql`. Needs
 *         PHP's curl extension and `proc_open()`. It REFUSES (exit 2) rather
 *         than skipping when anything is missing, because a skipped test reads
 *         exactly like a passing one.
 *
 *         `--old-core=<folder>` runs parts A and B alone with the classes in
 *         that folder (a copy of `web/_core` with SafeFetch.php and
 *         WebhookDispatcher.php taken from before #615), the same way.
 *
 *         `--old-save=<file>` runs part C alone with that file standing in for
 *         the save handler, and reports normally — used once to show part C is
 *         red on the code before #615 (`git show <before>:web/_apps/admin/
 *         integrations/webhooks/save.php > /tmp/old-save.php`).
 *
 * Exit:   0 only when every check ran and passed; 1 otherwise; 2 when it
 *         refused to run.
 *
 * WHAT IT CHANGES, AND PUTS BACK. Webhooks numbered 961501-961598 and their
 * deliveries, a made-up global administrator (961599) and a made-up ordinary
 * administrator (961600) with sign-ins of their own, every `webhooks.anyPort`
 * row (there is none on a fresh install), and the activity and error rows
 * written while it ran (removed by number at the end, and on the way out when
 * it stops early).
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/615
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/** The made-up global administrator. Nothing else in tools/ uses 9615xx/961600. */
const WD_ADMIN = 961599;
/** A made-up ORDINARY administrator (not global), for the port setting's gate. */
const WD_ORDINARY = 961600;
/** Webhook numbers this run owns: 961501 up to (not including) WD_ADMIN. */
const WD_FIRST_HOOK = 961501;
const WD_LAST_HOOK  = 961598;
/** What the test receiver writes in every reply, so a stored reply can be found. */
const WD_REPLY_MARKER = 'RECEIVER-REPLY-MARKER';

$sep  = DIRECTORY_SEPARATOR;
$repo = dirname(__DIR__);

$passed = 0;
$failed = 0;
/** @var list<string> */
$failedLabels = [];

function refuse(string $why): never
{
    echo "REFUSED: {$why}.\n";
    echo "Nothing more was checked, and this run fails. A skipped test reads exactly\n";
    echo "like a passing one, which is why this refuses rather than skipping.\n";
    exit(2);
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $failedLabels;
    if ($ok === true) {
        $passed += 1;
        echo "  ✓ {$name}\n";
        return;
    }
    $failed += 1;
    $failedLabels[] = $name;
    echo "  ✗ {$name}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

function head(string $text): void
{
    echo "\n{$text}\n";
}

/** @param list<mixed> $params @return list<array<string,mixed>> */
function rows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $out    = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $out;
}

/** @param list<mixed> $params */
function run(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

function connect(): mysqli
{
    $dbName = (string) getenv('SELFTEST_DB_NAME');
    if (preg_match('/^selftest_[A-Za-z0-9_]+$/', $dbName) !== 1) {
        refuse("set SELFTEST_DB_NAME to a database whose name starts with selftest_ (got '{$dbName}'). This test writes rows");
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli(
            getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
            getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
            getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
            $dbName,
            getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306
        );
    } catch (Throwable $e) {
        refuse("could not reach the database {$dbName}");
    }
    $db->set_charset('utf8mb4');
    return $db;
}

/** Highest id in a table now — rows above it were written by this run. */
function maxId(mysqli $db, string $table, string $col): int
{
    return (int) ($db->query('SELECT COALESCE(MAX(`' . $col . '`), 0) AS `n` FROM `' . $table . '`')->fetch_assoc()['n'] ?? 0);
}

/** Every request the test receiver has heard, oldest first. @return list<array<string,mixed>> */
function heard(string $log): array
{
    clearstatcache();
    $out = [];
    foreach (preg_split('/\R/', (string) @file_get_contents($log)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (is_array($row) === true) {
            $out[] = $row;
        }
    }
    return $out;
}

/** The requests whose address carries `t=<tag>`. @return list<array<string,mixed>> */
function heardTag(string $log, string $tag): array
{
    return array_values(array_filter(heard($log), static fn (array $r): bool => str_contains((string) $r['uri'], 't=' . $tag)));
}

/** A header from a heard request, case ignored, or null. */
function headerOf(array $request, string $name): ?string
{
    foreach ((array) $request['headers'] as $k => $v) {
        if (strcasecmp((string) $k, $name) === 0) {
            return (string) $v;
        }
    }
    return null;
}

// =============================================================================
// 🧪 THE WORKER — parts A and B, in a child process so a plant can swap the
//    classes it loads. Run as: php <this> --worker --core=<dir> --rport=<n>
//    --log=<receiver log> --phplog=<file> --out=<json>
// =============================================================================
$opts = getopt('', ['worker', 'core:', 'rport:', 'log:', 'phplog:', 'out:', 'old-save:', 'old-core:']);
if (isset($opts['worker']) === true) {
    $core   = (string) $opts['core'];
    $rport  = (int) $opts['rport'];
    $rlog   = (string) $opts['log'];
    $phplog = (string) $opts['phplog'];
    ini_set('log_errors', '1');
    ini_set('error_log', $phplog);
    ini_set('display_errors', '0');

    spl_autoload_register(static function (string $class) use ($core): void {
        if (str_starts_with($class, 'Portal\\Core\\') === false) {
            return;
        }
        $file = $core . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen('Portal\\Core\\'))) . '.php';
        if (is_file($file) === true) {
            require_once $file;
        }
    });

    // Every run starts with an empty receiver log, so a request heard by an
    // earlier run (a plant's) can never be counted as this run's.
    file_put_contents($rlog, '');

    $db = connect();
    \Portal\Core\App::init($db, ['webhooks' => ['enabled' => 'true', 'timeout' => '3']]);
    \Portal\Core\Logger::useConnection($db);
    $SF = \Portal\Core\SafeFetch::class;
    $errorsFrom   = maxId($db, 'tblErrors', 'errorID');
    $activityFrom = maxId($db, 'tblActivityLogs', 'logID');

    // Distinctive pieces of every address this worker hands out, for the
    // "never written down" sweep. `127.0.0.1` and `localhost` alone are NOT
    // used: a request's own client address is legitimately 127.0.0.1 here.
    $addressPieces = ['hooks.test', 'rebind.test', 'nip.io', '169.254.169.254', '10.0.0.1', '192.168.1.1',
        '2130706433', '0177.0.0.1', '0x7f000001', 'p615probe', ':' . $rport];
    $messages = [];

    $headers = [
        'Content-Type: application/json',
        'User-Agent: WebMS-Intra-Webhook/1.0',
        'X-Webhook-Event: selftest.sent',
        'X-Webhook-Delivery: 42',
        'X-Webhook-Signature: sha256=abc',
    ];
    $body = '{"event":"selftest.sent","data":{"x":"é"}}';

    // -------------------------------------------------------------------------
    head('A. SafeFetch::post() on its own');
    // -------------------------------------------------------------------------
    if (method_exists($SF, 'post') === false) {
        check('A0 SafeFetch has a post() method', false, 'it does not (the code before #615)');
    } else {
        $SF::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1'], 'port' => $rport]];
        $r = $SF::post('http://hooks.test/ok?t=A1', $body, $headers, ['timeoutSeconds' => 3]);
        $messages[] = $r['message'];
        $got = heardTag($rlog, 'A1');
        check('A1 a send through the pin succeeds (hooks.test resolves NOWHERE but the checked answer)',
            $r['ok'] === true && $r['status'] === 200 && $r['reason'] === '' && count($got) === 1, json_encode($r));
        check('A1b and the result carries no reply body, by design', array_key_exists('body', $r) === false, implode(',', array_keys($r)));
        $one = $got[0] ?? ['method' => '', 'body' => '', 'headers' => []];
        check('A1c the receiver got a POST with the body exactly as given', $one['method'] === 'POST' && $one['body'] === $body, json_encode($one));
        $sentOk = true;
        foreach ($headers as $h) {
            [$hn, $hv] = explode(': ', $h, 2);
            $sentOk = $sentOk && headerOf($one, $hn) === $hv;
        }
        check('A1d every header arrived exactly as given (signature, event, delivery, type, user agent)', $sentOk, json_encode($one['headers']));
        check('A1e no Accept-Encoding was sent: CURLOPT_ENCODING is not set (#716)', headerOf($one, 'Accept-Encoding') === null, (string) headerOf($one, 'Accept-Encoding'));
        $SF::$testResolverOverride = null;

        // A2. Refused before any connection. Where it LEADS: "refused".
        $refusedCases = [
            'http://127.0.0.1/p615probe',
            'http://localhost/p615probe',
            'http://169.254.169.254/latest/meta-data/',
            'http://10.0.0.1/p615probe',
            'http://192.168.1.1/p615probe',
            'http://[::1]/p615probe',
        ];
        $allRefused = true;
        $detail     = [];
        foreach ($refusedCases as $u) {
            $r = $SF::post($u, $body, $headers, ['timeoutSeconds' => 2]);
            $messages[] = $r['message'];
            if ($r['ok'] !== false || $r['reason'] !== 'refused' || $r['status'] !== 0) {
                $allRefused = false;
                $detail[]   = $u . ' => ' . $r['reason'];
            }
        }
        check('A2 ' . count($refusedCases) . ' private, internal and reserved addresses are refused with the word "refused"', $allRefused, implode('; ', $detail));

        // A2a. How it is WRITTEN: "not-allowed", with its own true sentence
        //      (#615 round 1: one word for both sent an administrator with
        //      `:8443` in their address to look at their name server).
        $notAllowedCases = [
            'http://2130706433/p615probe',
            'http://0177.0.0.1/p615probe',
            'http://0x7f000001/p615probe',
            'http://127.0.0.1:' . $rport . '/ok?t=A2port',
            'http://user:secret@hooks.test/ok?t=A2cred',
            'webcal://hooks.test/ok?t=A2webcal',
        ];
        $SF::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1'], 'port' => $rport]];
        $allNot = true;
        $detail = [];
        foreach ($notAllowedCases as $u) {
            $r = $SF::post($u, $body, $headers, ['timeoutSeconds' => 2]);
            $messages[] = $r['message'];
            if ($r['ok'] !== false || $r['reason'] !== 'not-allowed' || $r['message'] !== $SF::NOT_ALLOWED_MESSAGE) {
                $allNot   = false;
                $detail[] = $u . ' => ' . $r['reason'];
            }
        }
        $SF::$testResolverOverride = null;
        check('A2a ' . count($notAllowedCases) . ' addresses WRITTEN in a way not allowed (odd numbers, a port, a user name and password, webcal) give "not-allowed", not "refused"',
            $allNot, implode('; ', $detail));
        check('A2b and the receiver heard none of them', heardTag($rlog, 'A2port') === [] && heardTag($rlog, 'A2cred') === [] && heardTag($rlog, 'A2webcal') === []);

        $nip   = @dns_get_record('10.0.0.1.nip.io', DNS_A);
        $nipOk = is_array($nip) === true && count($nip) === 1 && ($nip[0]['ip'] ?? '') === '10.0.0.1';
        $r     = $SF::post('http://10.0.0.1.nip.io/p615probe', $body, $headers, ['timeoutSeconds' => 2]);
        $messages[] = $r['message'];
        check('A2c a NAME resolving to a private address is refused ('
            . ($nipOk === true ? 'it resolved to 10.0.0.1 here' : 'NOTE: it did not resolve here, so this proves "not found", not "private"') . ')',
            $r['reason'] === 'refused');

        // A3. The DNS-rebinding case inside ONE send: the handle is built for the
        //     checked address 192.0.2.1, and the connection is then sent
        //     somewhere else, as a resolver that changed its answer would.
        if (defined('CURLOPT_PREREQFUNCTION') === false) {
            check('A3 the pre-request check exists (libcurl 7.80+)', false, 'this libcurl has no CURLOPT_PREREQFUNCTION');
        } else {
            $priv = static function (string $m, array $a) use ($SF): mixed {
                $rm = new ReflectionMethod($SF, $m);
                return $rm->invoke(null, ...$a);
            };
            $o     = $priv('options', [['maxRedirects' => 0]]);
            $state = $priv('newHopState', []);
            $h     = $priv('buildHandle', ['http://rebind.test/ok?t=A3', 'rebind.test', 80, ['192.0.2.1'], $o, 3000, $state, ['body' => $body, 'headers' => $headers]]);
            if ($h instanceof CurlHandle) {
                curl_setopt($h, CURLOPT_CONNECT_TO, ['rebind.test:80:127.0.0.1:' . $rport]);
                curl_exec($h);
                $err = curl_errno($h);
                unset($h);
            } else {
                $err = -1;
            }
            check('A3 a send whose connection ends up at an address that was NOT checked is stopped (curl error 42)',
                $err === 42 && $state->prereqRefused === true, 'curl errno ' . $err);
            check('A3b and the receiver never heard it', heardTag($rlog, 'A3') === []);
            $state = $priv('newHopState', []);
            $h     = $priv('buildHandle', ['http://rebind.test/ok?t=A3ok', 'rebind.test', 80, ['127.0.0.1'], $o, 3000, $state, ['body' => $body, 'headers' => $headers]]);
            curl_setopt($h, CURLOPT_CONNECT_TO, ['rebind.test:80:127.0.0.1:' . $rport]);
            curl_exec($h);
            unset($h);
            check('A3c control: the same send checked for 127.0.0.1 gets through', count(heardTag($rlog, 'A3ok')) === 1 && $state->status === 200);
        }

        // A4-A6. A redirect, a big reply, a slow reply.
        $SF::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1'], 'port' => $rport]];
        $r = $SF::post('http://hooks.test/redirect?t=A4', $body, $headers, ['timeoutSeconds' => 3]);
        check('A4 a redirect is NOT followed: reported as the status it is', $r['ok'] === false && $r['status'] === 302 && $r['reason'] === 'http-status', json_encode($r));
        check('A4b and nothing was sent to where it pointed', heardTag($rlog, 'A4followed') === []);
        $r = $SF::post('http://hooks.test/big?t=A5', $body, $headers, ['timeoutSeconds' => 3, 'maxBytes' => 1024]);
        check('A5 a 2xx with a reply past the cap is delivered, and the reply cut off', $r['ok'] === true && $r['capped'] === true, json_encode($r));
        $r = $SF::post('http://hooks.test/fail?t=A5b', $body, $headers, ['timeoutSeconds' => 3]);
        check('A5b an error reply is not ok, and its words are not handed back', $r['ok'] === false && $r['status'] === 500 && $r['reason'] === 'http-status'
            && str_contains(json_encode($r), WD_REPLY_MARKER) === false, json_encode($r));
        $r = $SF::post('http://hooks.test/slow?t=A6', $body, $headers, ['timeoutSeconds' => 1]);
        check('A6 a reply slower than the limit gives "too-slow"', $r['ok'] === false && $r['reason'] === 'too-slow', json_encode($r));
        sleep(3); // the one-process receiver is still sleeping on A6
        $SF::$testResolverOverride = null;

        $threw = 0;
        foreach ([['nope' => 1], ['accept' => '*/*'], ['maxRedirects' => 2]] as $bad) {
            try {
                $SF::post('http://hooks.test/', '', [], $bad);
            } catch (InvalidArgumentException $e) {
                $threw++;
            }
        }
        try {
            $SF::post('http://hooks.test/', '', ["X-A: b\r\nX-Injected: yes"], []);
        } catch (InvalidArgumentException $e) {
            $threw++;
        }
        check('A7 an unknown option, a download-only option, and a header with a line break all throw', $threw === 4, (string) $threw);
        $said = '';
        try {
            $SF::post('http://hooks.test/', '', [], ['timeoutSeconds' => 0]);
        } catch (InvalidArgumentException $e) {
            $said = $e->getMessage();
        }
        check('A7b a mistake made through post() is reported as post()\'s, not get()\'s', str_starts_with($said, 'SafeFetch::post()'), $said);

        // A9. Any port, when the caller allows it (D-102) — and the pin is
        //     post()'s OWN: hooks.test is given an address with NO port of the
        //     test's, so the only entry pinning it is the one post() writes
        //     for the address's own port (#615 round 1: the test's port entry
        //     carried the connection when the real one was missing).
        $SF::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1']]];
        $r = $SF::post('http://hooks.test:' . $rport . '/ok?t=A9', $body, $headers, ['timeoutSeconds' => 3, 'anyPort' => true]);
        check('A9 with any port allowed, a send to a public-looking name on port ' . $rport . ' goes through post()\'s own pin',
            $r['ok'] === true && count(heardTag($rlog, 'A9')) === 1, json_encode($r));
        $r = $SF::post('http://hooks.test:' . $rport . '/ok?t=A9b', $body, $headers, ['timeoutSeconds' => 3]);
        check('A9b without it, the same address is "not-allowed" and nothing is sent', $r['reason'] === 'not-allowed' && heardTag($rlog, 'A9b') === [], json_encode($r));
        $SF::$testResolverOverride = null;
        $bad = 0;
        foreach (['http://127.0.0.1:' . $rport . '/ok?t=A9c', 'http://10.0.0.1:8443/p615probe', 'http://[::1]:8443/p615probe'] as $u) {
            $r = $SF::post($u, $body, $headers, ['timeoutSeconds' => 2, 'anyPort' => true]);
            $messages[] = $r['message'];
            $bad += $r['reason'] === 'refused' ? 0 : 1;
        }
        check('A9c with any port allowed, a private address on any port is still "refused", and nothing is sent', $bad === 0 && heardTag($rlog, 'A9c') === [], (string) $bad);
        $badPorts = 0;
        foreach (['0', '65536', '08443', '99999'] as $port) {
            $badPorts += $SF::normaliseUrl('https://example.org:' . $port . '/', true) === null ? 0 : 1;
        }
        check('A9d any port means 1 to 65535 written plainly: 0, 65536, 08443 and 99999 are still refused', $badPorts === 0, (string) $badPorts);
        check('A9e the CALENDAR rule is unchanged: normaliseUrl() and check() without the option refuse :8443',
            $SF::normaliseUrl('https://example.org:8443/x') === null && $SF::check('https://example.org:8443/x')['ok'] === false
            && $SF::normaliseUrl('https://example.org:8443/x', true) === 'https://example.org:8443/x'
            && $SF::normaliseUrl('https://example.org:443/x', true) === 'https://example.org/x');

        $leaky = array_filter($messages, static function (string $m) use ($addressPieces): bool {
            foreach ($addressPieces as $p) {
                if (str_contains($m, $p) === true) {
                    return true;
                }
            }
            return false;
        });
        check('A8 no message handed back holds any part of an address (' . count($messages) . ' messages)', $leaky === [], json_encode(array_values($leaky)));
    }

    // -------------------------------------------------------------------------
    head('B. WebhookDispatcher, through retryDue()');
    // -------------------------------------------------------------------------
    $db->query('DELETE FROM tblWebhookDeliveries WHERE webhookID BETWEEN ' . WD_FIRST_HOOK . ' AND ' . WD_LAST_HOOK);
    $db->query('DELETE FROM tblWebhooks WHERE webhookID BETWEEN ' . WD_FIRST_HOOK . ' AND ' . WD_LAST_HOOK);
    $others = (int) $db->query("SELECT COUNT(*) AS n FROM tblWebhookDeliveries WHERE status = 'failed'")->fetch_assoc()['n'];
    if ($others !== 0) {
        refuse("the database already holds {$others} failed deliveries this test did not make; retryDue() would send them");
    }
    $secret = 'selftest-secret-615';
    $hook = static function (int $id, string $url) use ($db, $secret): void {
        run($db, 'INSERT INTO tblWebhooks (webhookID, siteID, name, eventTypes, targetUrl, signingSecret, isActive) VALUES (?, 1, ?, \'all\', ?, ?, 1)',
            'isss', [$id, 'selftest615-' . $id, $url, $secret]);
    };
    $delivery = static function (int $hookId, string $payload, int $attempts = 0) use ($db): int {
        run($db, "INSERT INTO tblWebhookDeliveries (webhookID, eventType, payload, payloadHash, status, attemptCount) VALUES (?, 'selftest.sent', ?, ?, 'failed', ?)",
            'issi', [$hookId, $payload, hash('sha256', $payload), $attempts]);
        return (int) $db->insert_id;
    };
    $rowOf = static function (int $id) use ($db): array {
        return rows($db, 'SELECT *, TIMESTAMPDIFF(SECOND, NOW(), nextRetryAt) AS retryIn FROM tblWebhookDeliveries WHERE deliveryID = ?', 'i', [$id])[0] ?? [];
    };
    $retry = static function (): void {
        \Portal\Core\WebhookDispatcher::retryDue();
    };

    // B1. A webhook saved BEFORE #615, pointing at this machine — with any
    //     port ALLOWED by the installation, so that where it leads is the
    //     only thing that can refuse it.
    \Portal\Core\InstallationSettings::remember(['webhooks' => ['anyPort' => '1']]);
    $hook(961501, 'http://127.0.0.1:' . $rport . '/ok?t=B1');
    $d1 = $delivery(961501, '{"n":1}');
    $retry();
    $row = $rowOf($d1);
    check('B1 a webhook saved before #615 that points at this machine is refused at delivery',
        ($row['status'] ?? '') === 'failed' && (int) ($row['responseCode'] ?? -1) === 0 && ($row['responseSnippet'] ?? '') === 'refused', json_encode($row));
    check('B1b and the receiver behind it heard NOTHING', heardTag($rlog, 'B1') === []);
    check('B1c the refusal is retried like any failure: attempt 1, next try in about 120 seconds',
        (int) ($row['attemptCount'] ?? 0) === 1 && (int) ($row['retryIn'] ?? 0) >= 100 && (int) ($row['retryIn'] ?? 0) <= 125, json_encode($row));
    \Portal\Core\InstallationSettings::remember([]);

    // B1e. A webhook saved before #615 with a port, while the installation
    //      allows only the standard ones: "not-allowed", never "refused".
    \Portal\Core\SafeFetch::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1']]];
    $hook(961506, 'http://hooks.test:' . $rport . '/ok?t=B1e');
    $d6 = $delivery(961506, '{"n":6}');
    $retry();
    $row = $rowOf($d6);
    check('B1e a webhook with a port, on an installation allowing only the standard ports, is recorded "not-allowed" and nothing is sent',
        ($row['status'] ?? '') === 'failed' && ($row['responseSnippet'] ?? '') === 'not-allowed' && heardTag($rlog, 'B1e') === [], json_encode($row));

    // B7. The installation allows any port: the same webhook is delivered.
    \Portal\Core\InstallationSettings::remember(['webhooks' => ['anyPort' => '1']]);
    $d7 = $delivery(961506, '{"n":7}');
    $retry();
    $row = $rowOf($d7);
    check('B7 once the INSTALLATION allows any port, that webhook is delivered (through post()\'s own pin)',
        ($row['status'] ?? '') === 'delivered' && count(heardTag($rlog, 'B1e')) === 1, json_encode($row));
    // B7b. An organisation saying yes cannot loosen it: the merged settings
    //      say "1", the installation's own row says nothing.
    \Portal\Core\InstallationSettings::remember([]);
    \Portal\Core\App::init($db, ['webhooks' => ['enabled' => 'true', 'timeout' => '3', 'anyPort' => '1']]);
    $d7b = $delivery(961506, '{"n":7.5}');
    $retry();
    $row = $rowOf($d7b);
    check('B7b an ORGANISATION\'s "yes" (in the merged settings) does not loosen it: still "not-allowed"',
        ($row['responseSnippet'] ?? '') === 'not-allowed' && count(heardTag($rlog, 'B1e')) === 1, json_encode($row));
    \Portal\Core\App::init($db, ['webhooks' => ['enabled' => 'true', 'timeout' => '3']]);
    \Portal\Core\SafeFetch::$testResolverOverride = null;

    // B8. The time limit: 1 to 30 seconds whatever the setting says.
    $WD = \Portal\Core\WebhookDispatcher::class;
    if (method_exists($WD, 'timeoutSeconds') === false) {
        check('B8 the dispatcher works out its time limit in one place', false, 'no timeoutSeconds()');
    } else {
        $cases = [[null, 10], ['10', 10], ['30', 30], ['31', 30], ['120', 30], ['999999', 30], ['0', 1], ['-5', 1], ['abc', 1], [['x'], 1]];
        $wrong = [];
        foreach ($cases as [$in, $want]) {
            $got = $WD::timeoutSeconds($in);
            if ($got !== $want) {
                $wrong[] = json_encode($in) . ' gave ' . $got . ', not ' . $want;
            }
        }
        check('B8 the setting becomes 1 to 30 seconds: missing is 10, above 30 is 30, nothing sensible is 1', $wrong === [], implode('; ', $wrong));
    }
    // B8b. ...and the dispatcher really USES that answer: a setting of 0 is
    //      sent as 1 second, where 0 handed straight on would be refused by
    //      post() as a mistake and the delivery would never be attempted.
    \Portal\Core\App::init($db, ['webhooks' => ['enabled' => 'true', 'timeout' => '0']]);
    \Portal\Core\SafeFetch::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1'], 'port' => $rport]];
    $hook(961507, 'http://hooks.test/ok?t=B8');
    $d8 = $delivery(961507, '{"n":8}');
    $retry();
    $row = $rowOf($d8);
    check('B8b with the setting at 0, the dispatcher sends with its clamped limit and the webhook is delivered', ($row['status'] ?? '') === 'delivered', json_encode($row));
    \Portal\Core\App::init($db, ['webhooks' => ['enabled' => 'true', 'timeout' => '3']]);
    \Portal\Core\SafeFetch::$testResolverOverride = null;

    // B2. A public-looking name, delivered through the pin.
    \Portal\Core\SafeFetch::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1'], 'port' => $rport]];
    $hook(961502, 'http://hooks.test/ok?t=B2');
    $payload2 = '{"event":"selftest.sent","data":{"name":"Ånne"}}';
    $d2 = $delivery(961502, $payload2);
    $retry();
    $row = $rowOf($d2);
    $got = heardTag($rlog, 'B2');
    check('B2 a public-looking name is delivered THROUGH THE PIN (it resolves nowhere but the checked answer)',
        ($row['status'] ?? '') === 'delivered' && (int) ($row['responseCode'] ?? 0) === 200 && count($got) === 1, json_encode($row));
    $one = $got[0] ?? ['body' => '', 'headers' => []];
    check('B2b the body is the stored payload, byte for byte', $one['body'] === $payload2);
    check('B2c the signature, event, delivery number, type and user agent are exactly as before #615',
        headerOf($one, 'X-Webhook-Signature') === 'sha256=' . hash_hmac('sha256', $payload2, $secret)
        && headerOf($one, 'X-Webhook-Event') === 'selftest.sent'
        && headerOf($one, 'X-Webhook-Delivery') === (string) $d2
        && headerOf($one, 'Content-Type') === 'application/json'
        && headerOf($one, 'User-Agent') === 'WebMS-Intra-Webhook/1.0', json_encode($one['headers']));
    check('B2d the receiver\'s reply is NOT stored (the column is empty once delivered)',
        array_key_exists('responseSnippet', $row) === true && $row['responseSnippet'] === null, json_encode($row));

    // B3. A name that was fine, then RE-POINTED at a private address.
    \Portal\Core\SafeFetch::$testResolverOverride = ['127.0.0.1.nip.io' => ['ips' => ['127.0.0.1'], 'port' => $rport]];
    $hook(961503, 'http://127.0.0.1.nip.io/ok?t=B3');
    $d3 = $delivery(961503, '{"n":3}');
    $retry();
    $first = $rowOf($d3);
    check('B3 while its checked answer is acceptable, the webhook is delivered', ($first['status'] ?? '') === 'delivered', json_encode($first));
    \Portal\Core\SafeFetch::$testResolverOverride = null; // now the real name server answers: 127.0.0.1
    $loop = @dns_get_record('127.0.0.1.nip.io', DNS_A);
    $loopOk = is_array($loop) === true && ($loop[0]['ip'] ?? '') === '127.0.0.1';
    $d3b = $delivery(961503, '{"n":3.5}');
    $retry();
    $second = $rowOf($d3b);
    check('B3b re-pointed at this machine, the NEXT delivery is refused ('
        . ($loopOk === true ? 'it resolved to 127.0.0.1 here' : 'NOTE: it did not resolve here, so this proves "not found", not "private"') . ')',
        ($second['status'] ?? '') === 'failed' && ($second['responseSnippet'] ?? '') === 'refused', json_encode($second));
    check('B3c and the receiver heard the first delivery only', count(heardTag($rlog, 'B3')) === 1, (string) count(heardTag($rlog, 'B3')));

    // B4. An error reply: a word, never the reply.
    \Portal\Core\SafeFetch::$testResolverOverride = ['hooks.test' => ['ips' => ['127.0.0.1'], 'port' => $rport]];
    $hook(961504, 'http://hooks.test/fail?t=B4');
    $d4 = $delivery(961504, '{"n":4}');
    $retry();
    $row = $rowOf($d4);
    check('B4 an error reply is recorded as status 500 and the word "http-status", not the reply',
        ($row['status'] ?? '') === 'failed' && (int) ($row['responseCode'] ?? 0) === 500 && ($row['responseSnippet'] ?? '') === 'http-status', json_encode($row));
    \Portal\Core\SafeFetch::$testResolverOverride = null;

    // B5. Dead-lettering is unchanged.
    $hook(961505, 'http://169.254.169.254/latest/meta-data/');
    $d5 = $delivery(961505, '{"n":5}', 5);
    $retry();
    $row = $rowOf($d5);
    check('B5 a refused delivery on its last attempt is dead-lettered as before', ($row['status'] ?? '') === 'dead' && $row['nextRetryAt'] === null
        && ($row['responseSnippet'] ?? '') === 'refused', json_encode($row));

    // B6. Nothing anywhere holds the address, or a reply.
    $haystacks = [];
    foreach (rows($db, 'SELECT d.* FROM tblWebhookDeliveries d WHERE d.webhookID BETWEEN ? AND ?', 'ii', [WD_FIRST_HOOK, WD_LAST_HOOK]) as $r2) {
        unset($r2['payload'], $r2['payloadHash']);
        $haystacks['delivery #' . $r2['deliveryID']] = json_encode($r2, JSON_UNESCAPED_SLASHES);
    }
    foreach (rows($db, 'SELECT * FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]) as $r2) {
        $haystacks['error #' . $r2['errorID']] = json_encode($r2, JSON_UNESCAPED_SLASHES);
    }
    foreach (rows($db, 'SELECT * FROM tblActivityLogs WHERE logID > ?', 'i', [$activityFrom]) as $r2) {
        $haystacks['activity #' . $r2['logID']] = json_encode($r2, JSON_UNESCAPED_SLASHES);
    }
    clearstatcache();
    $haystacks['PHP error log'] = (string) @file_get_contents($phplog);
    $found = [];
    foreach ($haystacks as $where => $text) {
        foreach (array_merge($addressPieces, [WD_REPLY_MARKER]) as $piece) {
            if (str_contains($text, $piece) === true) {
                $found[] = $where . ' holds "' . $piece . '"';
            }
        }
    }
    check('B6 no delivery row, error row, activity row or PHP log line holds an address or a reply (' . count($haystacks) . ' places read)',
        $found === [], implode('; ', array_slice($found, 0, 6)));
    $refusalRows = (int) rows($db, "SELECT COUNT(*) AS n FROM tblErrors WHERE errorID > ? AND errorCode = 'SEND_REFUSED'", 'i', [$errorsFrom])[0]['n'];
    check('B6b and the refusals WERE recorded — the sweep above read real rows, not an empty log', $refusalRows >= 3, (string) $refusalRows);

    // Tidy this worker's own rows.
    $db->query('DELETE FROM tblWebhookDeliveries WHERE webhookID BETWEEN ' . WD_FIRST_HOOK . ' AND ' . WD_LAST_HOOK);
    $db->query('DELETE FROM tblWebhooks WHERE webhookID BETWEEN ' . WD_FIRST_HOOK . ' AND ' . WD_LAST_HOOK);
    run($db, 'DELETE FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]);

    file_put_contents((string) $opts['out'], json_encode(['passed' => $passed, 'failed' => $failed, 'failedLabels' => $failedLabels]));
    exit($failed === 0 ? 0 : 1);
}

// =============================================================================
// 🎬 THE ORCHESTRATOR
// =============================================================================
foreach (['curl_init', 'proc_open'] as $needed) {
    if (function_exists($needed) === false) {
        refuse("this test needs {$needed}(), which this PHP does not have");
    }
}
$db = connect();
$tables = (int) ($db->query('SELECT COUNT(*) AS `n` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE()')->fetch_assoc()['n'] ?? 0);
if ($tables < 100) {
    refuse("the database holds only {$tables} tables — load web/_sql/full_schema.sql first");
}
$webPort      = getenv('SELFTEST_WEB_PORT') !== false ? (int) getenv('SELFTEST_WEB_PORT') : 10930;
$receiverPort = getenv('WEBHOOK_SELFTEST_RECEIVER_PORT') !== false ? (int) getenv('WEBHOOK_SELFTEST_RECEIVER_PORT') : 10931;
foreach ([$webPort, $receiverPort] as $p) {
    if ($p < 1024 || $p > 65535) {
        refuse('the ports must be between 1024 and 65535');
    }
    $probe = @fsockopen('127.0.0.1', $p, $errNo, $errText, 0.5);
    if (is_resource($probe) === true) {
        fclose($probe);
        refuse("something already answers on port {$p}");
    }
}

$work = sys_get_temp_dir() . $sep . 'webhook-delivery-' . bin2hex(random_bytes(6));
mkdir($work, 0700, true);
file_put_contents($work . $sep . 'webhook-delivery.marker', 'made by tools/webhook-delivery-selftest.php');
$procs = [];
$errorsFrom   = maxId($db, 'tblErrors', 'errorID');
$activityFrom = maxId($db, 'tblActivityLogs', 'logID');

$cleanedUp = false;
$cleanUp = static function () use (&$cleanedUp, &$procs, $work, $db, $errorsFrom): void {
    if ($cleanedUp === true) {
        return;
    }
    $cleanedUp = true;
    foreach ($procs as $p) {
        if (is_resource($p) === true) {
            proc_terminate($p);
            proc_close($p);
        }
    }
    try {
        $db->query('DELETE FROM tblWebhookDeliveries WHERE webhookID BETWEEN ' . WD_FIRST_HOOK . ' AND ' . WD_LAST_HOOK);
        $db->query('DELETE FROM tblWebhooks WHERE webhookID BETWEEN ' . WD_FIRST_HOOK . ' AND ' . WD_LAST_HOOK);
        $db->query("DELETE FROM tblWebhookDeliveries WHERE webhookID IN (SELECT webhookID FROM tblWebhooks WHERE name LIKE 'selftest615%')");
        $db->query("DELETE FROM tblWebhooks WHERE name LIKE 'selftest615%'");
        run($db, 'DELETE FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]);
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (rows($db, "SELECT c.`TABLE_NAME` AS `t` FROM `information_schema`.`COLUMNS` c JOIN `information_schema`.`TABLES` t "
            . "ON t.`TABLE_SCHEMA` = c.`TABLE_SCHEMA` AND t.`TABLE_NAME` = c.`TABLE_NAME` AND t.`TABLE_TYPE` = 'BASE TABLE' "
            . "WHERE c.`TABLE_SCHEMA` = DATABASE() AND c.`COLUMN_NAME` = 'userID'") as $row) {
            run($db, 'DELETE FROM `' . $row['t'] . '` WHERE `userID` IN (?, ?)', 'ii', [WD_ADMIN, WD_ORDINARY]);
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ?', 's', ['webhooks.anyPort']);
    } catch (Throwable $e) {
        echo "  ! the clean-up could not finish (" . get_class($e) . "); rows numbered 9615xx may be left.\n";
    }
    if (is_file($work . DIRECTORY_SEPARATOR . 'webhook-delivery.marker') === true) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() === true ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($work);
    }
};
register_shutdown_function($cleanUp);

/** Start `php -S` and wait for it. */
$startServer = static function (int $port, array $args, string $logName) use (&$procs, $work): void {
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']); // one process: workers outlive a stopped parent
    $env['PORTAL_ENV'] = 'prod';
    $p = proc_open(
        array_merge([PHP_BINARY], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $work . DIRECTORY_SEPARATOR . $logName, 'a'], 2 => ['file', $work . DIRECTORY_SEPARATOR . $logName, 'a']],
        $pipes,
        $work,
        $env
    );
    if (is_resource($p) === false) {
        refuse('could not start a PHP web server');
    }
    $procs[] = $p;
    for ($i = 0; $i < 80; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($s !== false) {
            fclose($s);
            return;
        }
        usleep(100000);
    }
    refuse("the server on port {$port} never answered");
};

// ── The receiver: writes down every request it hears, one JSON line each ────
$rlog = $work . $sep . 'receiver.jsonl';
touch($rlog);
file_put_contents($work . $sep . 'receiver.php', <<<'RECEIVER'
<?php
// Written by tools/webhook-delivery-selftest.php for ONE run. Test only.
declare(strict_types=1);
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
file_put_contents((string) getenv('WD_RECEIVER_LOG'), json_encode([
    'method'  => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'uri'     => $uri,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body'    => (string) file_get_contents('php://input'),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
$path = (string) parse_url($uri, PHP_URL_PATH);
if ($path === '/redirect') {
    header('Location: /ok?t=A4followed', true, 302);
    echo 'RECEIVER-REPLY-MARKER redirect';
} elseif ($path === '/fail') {
    http_response_code(500);
    echo '<b>RECEIVER-REPLY-MARKER</b> internal details of the receiver';
} elseif ($path === '/slow') {
    sleep(3);
    echo 'RECEIVER-REPLY-MARKER slow';
} elseif ($path === '/big') {
    echo str_repeat('RECEIVER-REPLY-MARKER ', 10000);
} else {
    echo 'RECEIVER-REPLY-MARKER ok';
}
RECEIVER);
putenv('WD_RECEIVER_LOG=' . $rlog);
$startServer($receiverPort, ['-S', '127.0.0.1:' . $receiverPort, $work . $sep . 'receiver.php'], 'receiver.log');

// =============================================================================
// Parts A and B, through the worker
// =============================================================================
/**
 * Run parts A and B with the classes in `$coreDir`. Returns the worker's
 * summary; prints its output indented when `$show` is true.
 *
 * @return array{passed: int, failed: int, failedLabels: list<string>, exit: int}
 */
$runWorker = static function (string $coreDir, bool $show) use ($work, $sep, $receiverPort, $rlog): array {
    $out    = $work . $sep . 'worker-' . bin2hex(random_bytes(4)) . '.json';
    $phplog = $work . $sep . 'worker-php-' . bin2hex(random_bytes(4)) . '.log';
    $p = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=0', __FILE__, '--worker', '--core=' . $coreDir, '--rport=' . $receiverPort,
            '--log=' . $rlog, '--phplog=' . $phplog, '--out=' . $out],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $text = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $exit = proc_close($p);
    if ($show === true) {
        echo preg_replace('/^/m', '  ', rtrim((string) $text)) . "\n";
    }
    $summary = json_decode((string) @file_get_contents($out), true);
    if (is_array($summary) === false) {
        return ['passed' => 0, 'failed' => 1, 'failedLabels' => ['the worker did not finish: ' . substr(trim((string) $text), -300)], 'exit' => $exit];
    }
    $summary['exit'] = $exit;
    return $summary;
};

$oldSave = isset($opts['old-save']) === true ? (string) $opts['old-save'] : null;

if (isset($opts['old-core']) === true) {
    $oldCore = (string) $opts['old-core'];
    if (is_dir($oldCore) === false) {
        refuse('--old-core names no folder');
    }
    head('A and B — WITH THE CLASSES FROM ' . $oldCore);
    $res = $runWorker($oldCore, true);
    echo "\n  {$res['passed']} passed, {$res['failed']} failed (parts A and B only, with the substituted classes).\n";
    exit($res['failed'] === 0 ? 0 : 1);
}

if ($oldSave === null) {
    head('A and B — SafeFetch::post() and the dispatcher, on the real code');
    $real = $runWorker($repo . $sep . 'web' . $sep . '_core', true);
    check('A and B: every check in the worker passed (' . $real['passed'] . ' passed, ' . $real['failed'] . ' failed)',
        $real['failed'] === 0 && $real['exit'] === 0, implode(' | ', $real['failedLabels']));
}

// =============================================================================
// Part C — the admin page over real HTTP, in a copy of web/
// =============================================================================
$child = $work . $sep . 'portal';
$walk  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo . $sep . 'web', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$copied = 0;
foreach ($walk as $entry) {
    $relative = substr($entry->getPathname(), strlen($repo . $sep . 'web'));
    if (str_starts_with($relative, $sep . '_auth_keys') === true) {
        continue;
    }
    $to = $child . $sep . 'web' . $relative;
    if ($entry->isDir() === true) {
        @mkdir($to, 0700, true);
        continue;
    }
    copy($entry->getPathname(), $to);
    $copied++;
}
if ($copied < 100) {
    refuse("the copy of web/ holds only {$copied} files");
}
@mkdir($child . $sep . 'web' . $sep . '_auth_keys', 0700);
mkdir($child . $sep . 'sessions', 0700);
file_put_contents(
    $child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'auth_creds.php',
    "<?php\n// Written by tools/webhook-delivery-selftest.php for ONE run.\nreturn "
    . var_export([
        'db_host' => getenv('SELFTEST_DB_HOST') !== false ? (string) getenv('SELFTEST_DB_HOST') : '127.0.0.1',
        'db_user' => getenv('SELFTEST_DB_USER') !== false ? (string) getenv('SELFTEST_DB_USER') : 'root',
        'db_pass' => getenv('SELFTEST_DB_PASS') !== false ? (string) getenv('SELFTEST_DB_PASS') : '',
        'db_name' => (string) getenv('SELFTEST_DB_NAME'),
        'db_port' => getenv('SELFTEST_DB_PORT') !== false ? (int) getenv('SELFTEST_DB_PORT') : 3306,
    ], true) . ";\n"
);
file_put_contents($child . $sep . 'web' . $sep . '_auth_keys' . $sep . 'enc.key', bin2hex(random_bytes(32)));
// The router imitates web/public_html/.htaccess, as the other page tests do,
// and — TEST ONLY, never under web/ — tells SafeFetch that the made-up name
// public-hooks.test is at a public-looking address, so a control save works.
file_put_contents($child . $sep . 'router.php', <<<'ROUTER'
<?php
// Written by tools/webhook-delivery-selftest.php for ONE run. Test only.
declare(strict_types=1);
$root = (string) realpath(__DIR__ . '/web/public_html');
$uri  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)\.(?!well-known)#', $uri) === 1) { http_response_code(403); exit('403'); }
if (preg_match('#\.(php\d?|phtml|phar)$#i', $uri) === 1 && in_array($uri, ['/index.php', '/api-docs/index.php', '/error.php'], true) === false) { http_response_code(404); exit('404'); }
$target = $root . '/index.php';
$real   = ($uri === '/' || preg_match('#^/assets/?$#', $uri) === 1) ? false : realpath($root . $uri);
if ($real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) === true && is_file($real) === true) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') { $target = $real; } else { readfile($real); return true; }
}
require_once __DIR__ . '/web/_core/SafeFetch.php';
\Portal\Core\SafeFetch::$testResolverOverride = ['public-hooks.test' => ['ips' => ['93.184.215.14']]];
$_SERVER['SCRIPT_NAME']     = substr($target, strlen($root));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
return true;
ROUTER);
$savePath = $child . $sep . 'web' . $sep . '_apps' . $sep . 'admin' . $sep . 'integrations' . $sep . 'webhooks' . $sep . 'save.php';
$saveReal = (string) file_get_contents($savePath);
if ($oldSave !== null) {
    if (is_file($oldSave) === false) {
        refuse('--old-save names no file');
    }
    file_put_contents($savePath, (string) file_get_contents($oldSave));
}
$startServer($webPort, [
    '-d', 'session.save_path=' . $child . $sep . 'sessions',
    '-d', 'error_log=' . $work . $sep . 'portal-php-errors.log',
    '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'max_execution_time=120',
    '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
    '-S', '127.0.0.1:' . $webPort, '-t', $child . $sep . 'web' . $sep . 'public_html', $child . $sep . 'router.php',
], 'portal-server.log');

$jar  = $work . $sep . 'cookies.txt';
$http = static function (string $method, string $path, array $post = []) use ($webPort, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $webPort . $path);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    ]);
    if ($method === 'POST') {
        curl_setopt($h, CURLOPT_POST, true);
        curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($h);
    if ($raw === false) {
        return ['status' => 0, 'location' => '', 'body' => 'no answer'];
    }
    $headSize = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    return [
        'status'   => (int) curl_getinfo($h, CURLINFO_HTTP_CODE),
        'location' => preg_match('/^Location:\s*(\S+)\s*$/mi', substr((string) $raw, 0, $headSize), $mm) === 1 ? $mm[1] : '',
        'body'     => substr((string) $raw, $headSize),
    ];
};
$tokenOn = static function (string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $mm) === 1) {
        return html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
};

// The made-up global administrator.
$password = 'Wd' . bin2hex(random_bytes(8)) . '!9x';
$site     = (int) (rows($db, 'SELECT siteID FROM tblSites WHERE isActive = 1 ORDER BY siteID LIMIT 1')[0]['siteID'] ?? 0);
if ($site === 0) {
    refuse('the database has no organisation switched on');
}
foreach ([[WD_ADMIN, 'selftest-webhook-delivery', 1], [WD_ORDINARY, 'selftest-webhook-delivery-ordinary', 0]] as [$uid, $uname, $root]) {
    run($db, 'DELETE FROM `tblLocalAccounts` WHERE `username` = ?', 's', [$uname]);
    run($db, 'INSERT INTO `tblUsers` (`userID`, `fullName`, `emailAddress`, `isActive`, `isAdmin`, `isRootAdmin`, `createdAt`) VALUES (?, ?, ?, 1, 1, ?, NOW())',
        'issi', [$uid, 'Selftest webhook delivery ' . $uid, $uname . '@selftest.invalid', $root]);
    run($db, 'INSERT INTO `tblUserSites` (`siteID`, `userID`, `isActive`) VALUES (?, ?, 1)', 'ii', [$site, $uid]);
    run($db, 'INSERT INTO `tblLocalAccounts` (`userID`, `username`, `passwordHash`, `isVerified`) VALUES (?, ?, ?, 1)',
        'iss', [$uid, $uname, password_hash($password, PASSWORD_DEFAULT)]);
}
for ($i = 0; $i < 80 && $http('GET', '/login')['status'] === 0; $i++) {
    usleep(250000);
}
/** Sign in afresh as one of the two made-up administrators. */
$signIn = static function (string $uname) use ($http, $tokenOn, $jar, $password): void {
    @unlink($jar);
    $token = $tokenOn($http('GET', '/login')['body']);
    if ($token === '') {
        refuse('the sign-in page carried no form token');
    }
    $in = $http('POST', '/login', ['csrf_token' => $token, 'username' => $uname, 'password' => $password]);
    if ($in['status'] !== 302 && $in['status'] !== 303) {
        refuse("signing in as {$uname} did not answer a redirect (HTTP {$in['status']})");
    }
};
$signIn('selftest-webhook-delivery');

/**
 * Press Save on the webhook form, then read the page the redirect lands on.
 * @return array{status: int, page: string}
 */
$press = static function (array $fields) use ($http, $tokenOn): array {
    $page  = $http('GET', '/admin/integrations/webhooks');
    $token = $tokenOn($page['body']);
    $r     = $http('POST', '/admin/integrations/webhooks/save', array_merge(['csrf_token' => $token], $fields));
    $after = $http('GET', '/admin/integrations/webhooks');
    return ['status' => $r['status'], 'page' => $after['body']];
};
$hooksNamed = static function (string $name) use ($db): array {
    return rows($db, 'SELECT * FROM tblWebhooks WHERE name = ?', 's', [$name]);
};

$refusedMsg = 'This address cannot be used: it points at a private or internal network, or its name could not be found.';
$formatMsg        = 'This address cannot be used: a webhook needs an ordinary http:// or https:// address on the usual port (443 for https, 80 for http — this installation does not allow others), with no user name or password in it and no unusual spelling of a number.';
$formatMsgAnyPort = 'This address cannot be used: a webhook needs an ordinary http:// or https:// address, with no user name or password in it and no unusual spelling of a number.';

/**
 * Part C. Returns nothing; reports through check(). Run once on the real
 * page, and again for each plant.
 */
$partC = static function () use ($http, $press, $hooksNamed, $db, $refusedMsg, $formatMsg, $formatMsgAnyPort, $activityFrom, $errorsFrom, $site, $signIn): void {
    $db->query("DELETE FROM tblWebhookDeliveries WHERE webhookID IN (SELECT webhookID FROM tblWebhooks WHERE name LIKE 'selftest615%')");
    $db->query("DELETE FROM tblWebhooks WHERE name LIKE 'selftest615%'");
    run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ?', 's', ['webhooks.anyPort']);
    $signIn('selftest-webhook-delivery');
    $activityStart = maxId($db, 'tblActivityLogs', 'logID');

    // C1. Controls: a public-looking address is saved, in the standard spelling.
    $press(['name' => 'selftest615-c1', 'targetUrl' => 'http://public-hooks.test/hook', 'eventTypes' => 'all', 'isActive' => '1']);
    $c1 = $hooksNamed('selftest615-c1');
    check('C1 control: a public-looking address is saved', count($c1) === 1 && $c1[0]['targetUrl'] === 'http://public-hooks.test/hook', json_encode($c1));
    $press(['name' => 'selftest615-c1b', 'targetUrl' => 'HTTPS://Public-Hooks.TEST:443/x?y=1#part', 'eventTypes' => 'all', 'isActive' => '1']);
    $c1b = $hooksNamed('selftest615-c1b');
    check('C1b and stored in SafeFetch\'s one standard spelling, so what was checked is what is sent', ($c1b[0]['targetUrl'] ?? '') === 'https://public-hooks.test/x?y=1', json_encode($c1b));

    // C2. Refusals. Each: no row, the right message, and the message names no address.
    $nip   = @dns_get_record('10.0.0.1.nip.io', DNS_A);
    $nipOk = is_array($nip) === true && ($nip[0]['ip'] ?? '') === '10.0.0.1';
    $cases = [
        ['127.0.0.1',                 'http://127.0.0.1/p615probe1',                        $refusedMsg],
        ['localhost',                 'http://localhost/p615probe2',                        $refusedMsg],
        ['169.254.169.254 (metadata)', 'http://169.254.169.254/latest/meta-data/p615probe3', $refusedMsg],
        ['a 10.x address',            'https://10.20.30.40/p615probe4',                     $refusedMsg],
        ['[::1]',                     'http://[::1]/p615probe5',                            $refusedMsg],
        ['decimal 2130706433',        'http://2130706433/p615probe6',                       $formatMsg],
        ['octal 0177.0.0.1',          'http://0177.0.0.1/p615probe7',                       $formatMsg],
        ['hexadecimal 0x7f.0.0.1',    'http://0x7f.0.0.1/p615probe8',                       $formatMsg],
        ['an unusual port',           'http://public-hooks.test:8080/p615probe9',           $formatMsg],
        ['a user name and password',  'https://user:pw@public-hooks.test/p615probe14',      $formatMsg],
        ['a webcal:// address',       'webcal://public-hooks.test/p615probe15',             $formatMsg],
        ['a name resolving to 10.0.0.1 ('
            . ($nipOk === true ? 'it did here' : 'NOTE: it did not resolve here, so this proves "not found", not "private"') . ')',
                                      'http://10.0.0.1.nip.io/p615probe10',                 $refusedMsg],
    ];
    $n = 0;
    foreach ($cases as [$label, $url, $msg]) {
        $n++;
        $name = 'selftest615-c2-' . $n;
        $r    = $press(['name' => $name, 'targetUrl' => $url, 'eventTypes' => 'all', 'isActive' => '1']);
        $shown = str_contains($r['page'], htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
        check('C2 saving a webhook aimed at ' . $label . ' is refused, with the plain message and no row written',
            $hooksNamed($name) === [] && $shown === true, 'rows: ' . count($hooksNamed($name)) . '; message shown: ' . ($shown ? 'yes' : 'no'));
    }

    // C3. Updating: pausing a pre-#615 webhook works; switching it on does not; nor does re-aiming a good one.
    run($db, "INSERT INTO tblWebhooks (webhookID, siteID, name, eventTypes, targetUrl, signingSecret, isActive) VALUES (?, ?, 'selftest615-legacy', 'all', 'http://127.0.0.1/p615probe11', 'x', 1)",
        'ii', [WD_LAST_HOOK, $site]);
    $press(['webhookID' => (string) WD_LAST_HOOK, 'isActive' => '0']);
    $leg = $hooksNamed('selftest615-legacy');
    check('C3 PAUSING a webhook saved before #615 that points at this machine is never refused', (int) ($leg[0]['isActive'] ?? 1) === 0, json_encode($leg));
    $r = $press(['webhookID' => (string) WD_LAST_HOOK, 'isActive' => '1']);
    $leg = $hooksNamed('selftest615-legacy');
    check('C3b switching it back ON is refused, with the plain message', (int) ($leg[0]['isActive'] ?? 1) === 0
        && str_contains($r['page'], htmlspecialchars($refusedMsg, ENT_QUOTES, 'UTF-8')), json_encode($leg));
    $goodId = (int) ($hooksNamed('selftest615-c1')[0]['webhookID'] ?? 0);
    $press(['webhookID' => (string) $goodId, 'targetUrl' => 'http://10.9.8.7/p615probe12']);
    check('C3c re-aiming a good webhook at a private address is refused, and the stored address is unchanged',
        ($hooksNamed('selftest615-c1')[0]['targetUrl'] ?? '') === 'http://public-hooks.test/hook');
    $press(['webhookID' => (string) $goodId, 'targetUrl' => 'http://public-hooks.test/new']);
    check('C3d control: re-aiming it at a public-looking address is accepted', ($hooksNamed('selftest615-c1')[0]['targetUrl'] ?? '') === 'http://public-hooks.test/new');

    // C4. Recorded as refused — and nothing of any address, anywhere.
    $refusals = (int) rows($db, "SELECT COUNT(*) AS n FROM tblActivityLogs WHERE logID > ? AND activityType = 'WebhookTargetRefused' AND userID = ?", 'ii', [$activityStart, WD_ADMIN])[0]['n'];
    $c4Refusals = $refusals; // C6 below adds one more; C4 is judged after it
    $pieces = ['p615probe', '169.254.169.254', '10.20.30.40', '2130706433', '0177.0.0.1', '0x7f.0.0.1', 'nip.io', '10.9.8.7', ':8080'];
    $found  = [];
    foreach (rows($db, 'SELECT * FROM tblActivityLogs WHERE logID > ?', 'i', [$activityFrom]) as $row) {
        foreach ($pieces as $p) {
            if (str_contains((string) json_encode($row, JSON_UNESCAPED_SLASHES), $p) === true) {
                $found[] = 'activity #' . $row['logID'] . ' holds ' . $p;
            }
        }
    }
    foreach (rows($db, 'SELECT * FROM tblErrors WHERE errorID > ?', 'i', [$errorsFrom]) as $row) {
        foreach ($pieces as $p) {
            if (str_contains((string) json_encode($row, JSON_UNESCAPED_SLASHES), $p) === true) {
                $found[] = 'error #' . $row['errorID'] . ' holds ' . $p;
            }
        }
    }
    check('C4b no activity or error row written by any of it holds any part of an address that was typed', $found === [], implode('; ', array_slice($found, 0, 5)));

    // C5. The deliveries list shows a word, never a stored reply.
    $legId = WD_LAST_HOOK;
    run($db, "INSERT INTO tblWebhookDeliveries (webhookID, eventType, payload, status, attemptCount, responseCode, responseSnippet) VALUES (?, 'selftest.a', '{}', 'failed', 1, 0, 'refused')", 'i', [$legId]);
    run($db, "INSERT INTO tblWebhookDeliveries (webhookID, eventType, payload, status, attemptCount, responseCode, responseSnippet) VALUES (?, 'selftest.b', '{}', 'failed', 1, 500, '<b>RECEIVER-REPLY-MARKER</b> kept from before #615')", 'i', [$legId]);
    run($db, "INSERT INTO tblWebhookDeliveries (webhookID, eventType, payload, status, attemptCount, responseCode, responseSnippet) VALUES (?, 'selftest.c', '{}', 'dead', 6, 0, 'not-allowed')", 'i', [$legId]);
    $page = $http('GET', '/admin/integrations/webhooks')['body'];
    check('C5 the deliveries list says why one failed, in words', str_contains($page, 'the address was refused: it points at a private or internal network'));
    check('C5b and a "not-allowed" one is told the TRUE reason: a port, a user name or password, the scheme — not the name server',
        str_contains($page, 'the address is written in a way that is not allowed: a port number this installation does not allow'));
    check('C5c and never prints a reply stored before #615', str_contains($page, WD_REPLY_MARKER) === false);

    // C6. Which port numbers (D-102): an INSTALLATION setting, a global
    //     administrator only, never loosened by an organisation's row.
    $portRow = static fn (): array => rows($db, 'SELECT siteID, settingValue FROM tblSettings WHERE settingKey = ?', 's', ['webhooks.anyPort']);
    $signIn('selftest-webhook-delivery-ordinary');
    $r = $press(['action' => 'webhook_ports', 'anyPort' => '1']);
    check('C6 an ORDINARY administrator cannot allow other ports: nothing is stored, and they are told why',
        $portRow() === [] && str_contains($r['page'], 'Only a global administrator can choose which port numbers'), json_encode($portRow()));
    $signIn('selftest-webhook-delivery');
    $activityBefore = maxId($db, 'tblActivityLogs', 'logID');
    $press(['action' => 'webhook_ports', 'anyPort' => '1']);
    $changed = (int) rows($db, "SELECT COUNT(*) AS n FROM tblActivityLogs WHERE logID > ? AND activityType = 'WebhookPortsChanged' AND userID = ?", 'ii', [$activityBefore, WD_ADMIN])[0]['n'];
    check('C6b a global administrator can: the INSTALLATION\'s row says 1, and the change is in the activity log',
        $portRow() === [['siteID' => null, 'settingValue' => '1']] && $changed === 1, json_encode($portRow()) . ' / ' . $changed);
    $press(['name' => 'selftest615-c6', 'targetUrl' => 'https://public-hooks.test:8443/p', 'eventTypes' => 'all', 'isActive' => '1']);
    check('C6c then a public-looking address on port 8443 is saved, port and all', ($hooksNamed('selftest615-c6')[0]['targetUrl'] ?? '') === 'https://public-hooks.test:8443/p', json_encode($hooksNamed('selftest615-c6')));
    $r = $press(['name' => 'selftest615-c6d', 'targetUrl' => 'http://127.0.0.1:8443/p615probe13', 'eventTypes' => 'all', 'isActive' => '1']);
    check('C6d and a PRIVATE address on any port is still refused', $hooksNamed('selftest615-c6d') === []
        && str_contains($r['page'], htmlspecialchars($refusedMsg, ENT_QUOTES, 'UTF-8')));
    $r = $press(['name' => 'selftest615-c6g', 'targetUrl' => 'https://user:pw@public-hooks.test:8443/p615probe16', 'eventTypes' => 'all', 'isActive' => '1']);
    check('C6g a user name and password are refused whatever the port rule, with the any-port wording', $hooksNamed('selftest615-c6g') === []
        && str_contains($r['page'], htmlspecialchars($formatMsgAnyPort, ENT_QUOTES, 'UTF-8')));
    $press(['action' => 'webhook_ports', 'anyPort' => '0']);
    $r = $press(['name' => 'selftest615-c6e', 'targetUrl' => 'https://public-hooks.test:8443/q', 'eventTypes' => 'all', 'isActive' => '1']);
    check('C6e put back to standard ports, port 8443 is refused again with the standard-ports wording', $hooksNamed('selftest615-c6e') === []
        && str_contains($r['page'], htmlspecialchars($formatMsg, ENT_QUOTES, 'UTF-8')));
    run($db, "INSERT INTO tblSettings (siteID, settingKey, settingValue) VALUES (?, 'webhooks.anyPort', '1')", 'i', [$site]);
    $press(['name' => 'selftest615-c6f', 'targetUrl' => 'https://public-hooks.test:8443/r', 'eventTypes' => 'all', 'isActive' => '1']);
    check('C6f an ORGANISATION\'s own row saying 1 does not loosen it (the installation\'s says 0)', $hooksNamed('selftest615-c6f') === []);
    run($db, 'DELETE FROM `tblSettings` WHERE `settingKey` = ?', 's', ['webhooks.anyPort']);

    $refusals = (int) rows($db, "SELECT COUNT(*) AS n FROM tblActivityLogs WHERE logID > ? AND activityType = 'WebhookTargetRefused' AND userID = ?", 'ii', [$activityStart, WD_ADMIN])[0]['n'];
    check('C4 every refusal about where an address LEADS is in the activity log (9: six typed, one switching on, one re-aimed, one on port 8443) — and none about how one is written',
        $refusals === 9 && $c4Refusals === 8, $c4Refusals . ' then ' . $refusals);
};

head('C. The admin page, over real HTTP' . ($oldSave !== null ? ' — WITH THE SAVE HANDLER FROM ' . $oldSave : ''));
$partC();

if ($oldSave !== null) {
    head('Summary');
    echo "  {$passed} passed, {$failed} failed (part C only, with the substituted save handler).\n";
    exit($failed === 0 ? 0 : 1);
}

// =============================================================================
// P. Plants — each guard taken out of a COPY, which must go red, then green
// =============================================================================
head('P. Plants: each guard taken out must turn the run red, on the check meant to catch it');

/** Replace exactly one occurrence; false when the text is not there exactly once. */
$plantText = static function (string $source, string $from, string $to): ?string {
    if (substr_count($source, $from) !== 1) {
        return null;
    }
    return str_replace($from, $to, $source);
};

// Worker plants: a copy of web/_core with one file changed.
$coreCopy = $work . $sep . 'core-copy';
mkdir($coreCopy, 0700);
foreach (new DirectoryIterator($repo . $sep . 'web' . $sep . '_core') as $f) {
    if ($f->isFile() === true && $f->getExtension() === 'php') {
        copy($f->getPathname(), $coreCopy . $sep . $f->getFilename());
    }
}
$sfReal = (string) file_get_contents($coreCopy . $sep . 'SafeFetch.php');
$wdReal = (string) file_get_contents($coreCopy . $sep . 'WebhookDispatcher.php');

// The ORIGINAL pre-#615 send, as it stood in WebhookDispatcher::attemptDelivery()
// (commit 29e33d77), so "red on the old code" is proved on every run without
// needing that commit in the checkout.
$originalSend = <<<'PHP'
            $code        = 0;
            $respSnippet = '';
            $curlError   = '';
            $ch = curl_init($targetUrl);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'User-Agent: WebMS-Intra-Webhook/1.0',
                        'X-Webhook-Event: ' . $eventType,
                        'X-Webhook-Delivery: ' . $deliveryId,
                        'X-Webhook-Signature: ' . $signature,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => max(1, $timeout),
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                $rawResponse = curl_exec($ch);
                $respSnippet = is_string($rawResponse) ? mb_substr($rawResponse, 0, 500) : '';
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($code === 0) {
                    $curlError = curl_error($ch);
                }
            }
            $sent = ['ok' => ($code >= 200 && $code < 300), 'reason' => $curlError !== '' ? mb_substr('curl: ' . $curlError, 0, 500) : $respSnippet];
            $sent['reason'] = $sent['reason'] === '' ? null : $sent['reason'];
            $sent['status'] = $code;
PHP;
$sendStart = strpos($wdReal, '            $sent = SafeFetch::post(');
$sendEnd   = strpos($wdReal, '            $code = (int) $sent[\'status\'];');
$wdOld = null;
if ($sendStart !== false && $sendEnd !== false && $sendEnd > $sendStart) {
    $wdOld = substr($wdReal, 0, $sendStart) . $originalSend . "\n" . substr($wdReal, $sendEnd);
    // The original stored the reply even when it was delivered.
    $wdOld = str_replace('$reason = $delivered === true ? null : (string) $sent[\'reason\'];', '$reason = $sent[\'reason\'];', $wdOld);
}

$wpReal = (string) file_get_contents($coreCopy . $sep . 'WebhookPorts.php');
$originals = ['SafeFetch.php' => $sfReal, 'WebhookDispatcher.php' => $wdReal, 'WebhookPorts.php' => $wpReal];

$workerPlants = [
    [
        'P1 the ORIGINAL pre-#615 send put back in the dispatcher (plain curl, reply stored)',
        'WebhookDispatcher.php', $wdOld,
        ['B1 ', 'B1b', 'B2 ', 'B6 '],
    ],
    [
        'P2 SafeFetch::post() ignores check()\'s verdict (the delivery-time check gone)',
        'SafeFetch.php',
        $plantText($sfReal, "        \$checked = self::check(\$parts['url'], \$anyPort);\n        if (\$checked['ok'] === false) {", "        \$checked = self::check(\$parts['url'], \$anyPort);\n        if (false) {"),
        ['A2 '],
    ],
    [
        'P3 the whole pin taken out of the shared curl handle (CURLOPT_RESOLVE)',
        'SafeFetch.php',
        $plantText($sfReal, "            CURLOPT_RESOLVE           => \$resolve,\n", ''),
        ['A1 ', 'B2 '],
    ],
    [
        'P4 the pre-request check taken out',
        'SafeFetch.php',
        $plantText($sfReal, "if (defined('CURLOPT_PREREQFUNCTION') === true) {", 'if (false) {'),
        ['A3 '],
    ],
    [
        'P5 a refused send recorded WITH the host',
        'SafeFetch.php',
        $plantText($sfReal, "        if (\$checked['ok'] === false) {\n            self::logProblem('SEND_REFUSED', '', 0, false);", "        if (\$checked['ok'] === false) {\n            self::logProblem('SEND_REFUSED', \$parts['host'], 0, true);"),
        ['B6 '],
    ],
    [
        'P6 ONLY post()\'s own pin entry taken out (the test\'s port entry left in place)',
        'SafeFetch.php',
        $plantText($sfReal, "        \$resolve   = [\$host . ':' . \$port . ':' . implode(',', \$pinned)];", "        \$resolve   = [];"),
        ['A9 ', 'B7 '],
    ],
    [
        'P7 the 1-30 second clamp taken out of timeoutSeconds()',
        'WebhookDispatcher.php',
        $plantText($wdReal, "        return max(1, min(self::MAX_TIMEOUT_SECONDS, \$seconds));", "        return \$seconds;"),
        ['B8 '],
    ],
    [
        'P8 the dispatcher reading the time-limit setting raw, round the clamp',
        'WebhookDispatcher.php',
        $plantText($wdReal, "\$timeout   = self::timeoutSeconds(App::settings('webhooks.timeout'));", "\$timeout   = (int) (App::settings('webhooks.timeout') ?? '10');"),
        ['B8b'],
    ],
    [
        'P9 the port rule read from the MERGED settings (an organisation could loosen it)',
        'WebhookPorts.php',
        $plantText($wpReal, "\$layer = InstallationSettings::layer();", "\$layer = App::settings();"),
        ['B7b'],
    ],
    [
        'P10 the dispatcher never passing the port rule on',
        'WebhookDispatcher.php',
        $plantText($wdReal, "'anyPort'               => WebhookPorts::allowsAnyPort(),", "'anyPort'               => false,"),
        ['B7 '],
    ],
    [
        'P11 the CALENDAR rule loosened: any port by default',
        'SafeFetch.php',
        // check() is what get() calls, with no option: its default IS the calendar rule.
        $plantText($sfReal, "public static function check(string \$url, bool \$anyPort = false)", "public static function check(string \$url, bool \$anyPort = true)"),
        ['A9e'],
    ],
    [
        'P12 post() ignoring the port option',
        'SafeFetch.php',
        $plantText($sfReal, "self::parse(\$url, \$anyPort) : null;", "self::parse(\$url) : null;"),
        ['A9 '],
    ],
    [
        'P13 "not-allowed" folded back into "refused"',
        'SafeFetch.php',
        $plantText($sfReal, "return self::sent(false, 0, 0, self::NOT_ALLOWED_MESSAGE, 'not-allowed', false);", "return self::sent(false, 0, 0, self::REFUSED_MESSAGE, 'refused', false);"),
        ['A2a', 'B1e'],
    ],
];
foreach ($workerPlants as [$label, $file, $planted, $mustFail]) {
    if ($planted === null) {
        check($label . ': the plant could be applied (its text was found exactly once)', false, 'the text moved; update the plant');
        continue;
    }
    file_put_contents($coreCopy . $sep . $file, $planted);
    $res = $runWorker($coreCopy, false);
    $missed = [];
    foreach ($mustFail as $prefix) {
        $hit = false;
        foreach ($res['failedLabels'] as $l) {
            $hit = $hit || str_starts_with($l, $prefix);
        }
        if ($hit === false) {
            $missed[] = trim($prefix);
        }
    }
    check($label . ': caught by ' . implode(', ', array_map('trim', $mustFail)), $missed === [],
        'not caught by ' . implode(', ', $missed) . '; it failed: ' . implode(' | ', array_slice($res['failedLabels'], 0, 6)));
    file_put_contents($coreCopy . $sep . $file, $originals[$file]);
}
$back = $runWorker($coreCopy, false);
check('P1-P13 put back: the copy is green again (' . $back['passed'] . ' passed)', $back['failed'] === 0, implode(' | ', $back['failedLabels']));

// Page plants: the copy's save handler with one guard taken out.
$pagePlants = [
    [
        'P14 the save-time check taken out of the save handler',
        $plantText($saveReal, "    if (SafeFetch::check(\$normal, \$anyPort)['ok'] === false) {", '    if (false) {'),
        ['C2 saving a webhook aimed at 127.0.0.1', 'C2 saving a webhook aimed at 169.254', 'C4 '],
    ],
    [
        'P15 the address checked on EVERY update, pausing included',
        $plantText($saveReal, "(\$urlArrived === true || \$switchingOn === true)", 'true'),
        ['C3 PAUSING'],
    ],
    [
        'P16 switching a webhook back on no longer checks its address',
        $plantText($saveReal, "(\$urlArrived === true || \$switchingOn === true)", '($urlArrived === true)'),
        ['C3b'],
    ],
    [
        'P17 the port setting changeable by ANY administrator',
        $plantText($saveReal, "        App::isRootAdmin() === true,\n", "        true,\n"),
        ['C6 '],
    ],
    [
        'P18 the save page ignoring the installation\'s port setting',
        $plantText($saveReal, "\$anyPort = WebhookPorts::allowsAnyPort();", "\$anyPort = false;"),
        ['C6c'],
    ],
    [
        'P19 webcal:// let through and quietly stored as https',
        $plantText($saveReal, "preg_match('~^https?://~i', \$targetUrl) === 1 ? SafeFetch::normaliseUrl(\$targetUrl, \$anyPort) : null", "SafeFetch::normaliseUrl(\$targetUrl, \$anyPort)"),
        ['C2 saving a webhook aimed at a webcal'],
    ],
];
foreach ($pagePlants as [$label, $planted, $mustFail]) {
    if ($planted === null) {
        check($label . ': the plant could be applied (its text was found exactly once)', false, 'the text moved; update the plant');
        continue;
    }
    file_put_contents($savePath, $planted);
    $before = $failedLabels;
    $bp = $passed;
    $bf = $failed;
    ob_start();
    $partC();
    ob_end_clean();
    $newFails = array_slice($failedLabels, count($before));
    // The plant's own failures are expected: take them back out of the totals.
    $passed       = $bp;
    $failed       = $bf;
    $failedLabels = $before;
    $missed = [];
    foreach ($mustFail as $prefix) {
        $hit = false;
        foreach ($newFails as $l) {
            $hit = $hit || str_starts_with($l, $prefix);
        }
        if ($hit === false) {
            $missed[] = $prefix;
        }
    }
    check($label . ': caught by ' . implode(', ', $mustFail), $missed === [], 'not caught by ' . implode(', ', $missed) . '; it failed: ' . implode(' | ', array_slice($newFails, 0, 6)));
    file_put_contents($savePath, $saveReal);
}
$before = $failedLabels;
$bp     = $passed;
$bf     = $failed;
ob_start();
$partC();
ob_end_clean();
$again        = array_slice($failedLabels, count($before));
$passed       = $bp;
$failed       = $bf;
$failedLabels = $before;
check('P14-P19 put back: part C is green again', $again === [], implode(' | ', $again));

head('Summary');
echo "  {$passed} passed, {$failed} failed here; the worker's own " . $real['passed'] . " checks (parts A and B) are the one line\n"
    . "  \"A and B\" above, so " . ($passed + $real['passed'] - 1) . " checks passed in all.\n";
exit($failed === 0 ? 0 : 1);
