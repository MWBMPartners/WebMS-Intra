<?php
// Path: tools/https-standin.php
/**
 * -----------------------------------------------------------------------------
 * A stand-in for the outside HTTPS services the portal calls, for self-tests 🎭🔒
 * -----------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------
 * The portal reaches Microsoft 365 (`login.microsoftonline.com`,
 * `graph.microsoft.com`), Google (`oauth2.googleapis.com`,
 * `gmail.googleapis.com`) and reCAPTCHA (`www.google.com`) at addresses written
 * into its code. Until #1006 and #1017 (12 November 2026) no self-test could
 * send a message through either mail route, or have reCAPTCHA refuse a form,
 * because nothing could stand where those services stand - so the two record
 * faults those issues describe could only be found by reading.
 *
 * This is that stand-in, and it needs NO LINE OF THE PORTAL CHANGED:
 *
 *   - It is a web PROXY on 127.0.0.1. A test starts the portal's web server
 *     with `https_proxy` set to it in the server's environment; libcurl (which
 *     every one of those calls uses) then asks this proxy to connect it to,
 *     say, `graph.microsoft.com:443`.
 *   - Instead of connecting anywhere, it answers that connection ITSELF, with
 *     a certificate for that name signed by an authority it made for this run
 *     (`ca.pem` in its folder). The test starts the portal's server trusting
 *     that file through `openssl.cafile` and `curl.cainfo` - BOTH, because
 *     PHP's curl reads the first and ignores the second whenever the first is
 *     set (`tools/https-standin-client.php` has the measurement) - so the
 *     portal trusts it, and nothing else on the machine does. Certificate checking stays ON in the portal
 *     (CURLOPT_SSL_VERIFYPEER and VERIFYHOST are unchanged), so a test using
 *     this proves the real code path, checks and all.
 *   - It answers from `answers.json` in its folder, read afresh for EVERY
 *     request, so a test changes what a service says between steps by
 *     rewriting that file. A name or path it has no answer for is refused
 *     (403 to the connection, 404 to the request): NOTHING IS EVER FORWARDED.
 *     A portal behind this proxy cannot reach the internet at all.
 *   - It writes one line per request to `requests.jsonl`: the name, the
 *     method, the path, the status it answered and the length of what it was
 *     sent - never the request's body or headers, which carry a made-up but
 *     still bearer token and the message itself.
 *
 * answers.json:
 *   { "graph.microsoft.com": [ {"path": "/v1.0/users/", "status": 202, "body": ""} ],
 *     "login.microsoftonline.com": [ {"path": "/", "status": 200,
 *         "json": {"access_token": "x", "expires_in": 3600}} ] }
 *   `path` is a PREFIX of the request's path; the first matching entry wins.
 *   `json` is sent as JSON; otherwise `body` is sent as written. An entry
 *   with `"drop": true` sends NOTHING: the request is read and the connection
 *   closed, so the caller sees a network failure, not an answer (#1043).
 *   It is logged with status 0.
 *
 * Usage (by a test, through `tools/https-standin-client.php`, never by hand):
 *   php tools/https-standin.php <empty folder> <name>[,<name>...] [--watch-parent]
 *   It writes `ca.pem` and then `port` (the proxy's port, written last, so a
 *   test that sees `port` can rely on `ca.pem`). With --watch-parent it stops
 *   when its standard input closes, which is when the test that started it
 *   ends, however it ends. It also stops when a file `stop` appears in its
 *   folder, and after an hour whatever happens.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * - Prove anything about the real services: it says what a test tells it to.
 *   A test using it proves what the PORTAL does with an answer, never that
 *   Microsoft, Google or reCAPTCHA would give that answer.
 * - Serve two connections at once: it handles one, to its end, then the next.
 *   PHP's built-in server runs one request at a time here, and each of the
 *   portal's calls is one request after another, so nothing waits on it.
 * - HTTP/2, or a request body sent in chunks: libcurl offers neither to a
 *   server that does not ask (this one negotiates nothing), so neither comes.
 *
 * @package   Portal\Tools
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   1.0.0
 * @link      https://github.com/MWBMPartners/WebMS-Intra/issues/1006
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || isset($argv[1], $argv[2]) !== true || is_dir($argv[1]) !== true) {
    fwrite(STDERR, "usage: php https-standin.php <empty folder> <name>[,<name>...] [--watch-parent]\n");
    exit(2);
}
$dir         = rtrim((string) $argv[1], DIRECTORY_SEPARATOR);
$names       = array_values(array_filter(array_map('trim', explode(',', (string) $argv[2])), static fn (string $n): bool => preg_match('/^[a-z0-9.-]+$/', $n) === 1));
$watchParent = ($argv[3] ?? '') === '--watch-parent';
if ($names === []) {
    fwrite(STDERR, "no server names were given\n");
    exit(2);
}

// =============================================================================
// 🪪 The authority and the one certificate, made for this run
// =============================================================================
// PHP's own openssl functions, with a small configuration file for the two
// extensions a modern client insists on: "may sign others" on the authority,
// and the list of names (subjectAltName) on the server's certificate - a
// client ignores the old "common name" when that list exists, and curl
// refuses a server certificate without it.
$config = $dir . DIRECTORY_SEPARATOR . 'openssl.cnf';
file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n"
    . "[authority]\nbasicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign, cRLSign\nsubjectKeyIdentifier = hash\n"
    . "[server]\nbasicConstraints = critical, CA:FALSE\nkeyUsage = critical, digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth\n"
    . 'subjectAltName = ' . implode(', ', array_map(static fn (string $n): string => 'DNS:' . $n, $names)) . "\n"
    . "subjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid\n");
$newKey = static fn (): OpenSSLAsymmetricKey => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$caKey  = $newKey();
$caCsr  = openssl_csr_new(['commonName' => 'Portal self-test stand-in authority'], $caKey, ['config' => $config, 'digest_alg' => 'sha256']);
$ca     = openssl_csr_sign($caCsr, null, $caKey, 2, ['config' => $config, 'x509_extensions' => 'authority', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
$leafKey = $newKey();
$leafCsr = openssl_csr_new(['commonName' => $names[0]], $leafKey, ['config' => $config, 'digest_alg' => 'sha256']);
$leaf    = openssl_csr_sign($leafCsr, $ca, $caKey, 2, ['config' => $config, 'x509_extensions' => 'server', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
if ($ca === false || $leaf === false) {
    fwrite(STDERR, 'the certificates could not be made: ' . (string) openssl_error_string() . "\n");
    exit(2);
}
openssl_x509_export($ca, $caPem);
openssl_x509_export($leaf, $leafPem);
openssl_pkey_export($leafKey, $leafKeyPem);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'ca.pem', $caPem);
file_put_contents($dir . DIRECTORY_SEPARATOR . 'server.pem', $leafPem . $leafKeyPem);
chmod($dir . DIRECTORY_SEPARATOR . 'server.pem', 0600);

// =============================================================================
// 🎧 The proxy
// =============================================================================
$listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create([
    'ssl' => ['local_cert' => $dir . DIRECTORY_SEPARATOR . 'server.pem', 'verify_peer' => false, 'allow_self_signed' => false],
]));
if ($listener === false) {
    fwrite(STDERR, "could not listen: {$errorText}\n");
    exit(2);
}
$port = (int) substr((string) strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
// Written under another name and renamed, so a test never reads half a number.
file_put_contents($dir . DIRECTORY_SEPARATOR . 'port.tmp', (string) $port);
rename($dir . DIRECTORY_SEPARATOR . 'port.tmp', $dir . DIRECTORY_SEPARATOR . 'port');
if ($watchParent === true) {
    stream_set_blocking(STDIN, false);
}

/** One line of the request log. Never the body, never a header. */
$note = static function (array $line) use ($dir): void {
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'requests.jsonl', json_encode($line) . "\n", FILE_APPEND | LOCK_EX);
};

/**
 * Read one HTTP head (up to the blank line) from a connection, within the
 * deadline. Returns the head, or null when the other side went away or took
 * too long.
 */
$readHead = static function ($socket, float $until): ?string {
    $head = '';
    while (str_contains($head, "\r\n\r\n") === false) {
        if (microtime(true) > $until || strlen($head) > 65536) {
            return null;
        }
        $line = fgets($socket, 8192);
        if ($line === false) {
            if (feof($socket) === true) {
                return null;
            }
            usleep(1000);
            continue;
        }
        $head .= $line;
    }

    return $head;
};

$started = time();
while (true) {
    if (file_exists($dir . DIRECTORY_SEPARATOR . 'stop') === true || time() - $started > 3600) {
        break;
    }
    if ($watchParent === true) {
        // Nothing is ever written to this pipe; its end is the only news it carries.
        fread(STDIN, 16);
        if (feof(STDIN) === true) {
            break;
        }
    }
    $read   = [$listener];
    $write  = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 250000) < 1) {
        continue;
    }
    $client = @stream_socket_accept($listener, 1);
    if ($client === false) {
        continue;
    }
    $until = microtime(true) + 15;
    stream_set_timeout($client, 15);
    try {
        // 1. The proxy request: "CONNECT name:443 HTTP/1.1".
        $head = $readHead($client, $until);
        if ($head === null || preg_match('#^CONNECT ([a-z0-9.-]+):443 HTTP/1\.[01]\r\n#i', $head, $m) !== 1) {
            fwrite($client, "HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            $note(['host' => '', 'method' => 'not CONNECT', 'path' => '', 'status' => 405, 'bytes' => 0]);
            continue;
        }
        $host = strtolower($m[1]);
        if (in_array($host, $names, true) === false) {
            // A name this run did not make a certificate for: refused, never forwarded.
            fwrite($client, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            $note(['host' => $host, 'method' => 'CONNECT', 'path' => '', 'status' => 403, 'bytes' => 0]);
            continue;
        }
        fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");

        // 2. The secure connection, answered here with this run's certificate.
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) !== true) {
            $note(['host' => $host, 'method' => 'TLS', 'path' => '', 'status' => 0, 'bytes' => 0]);
            continue;
        }

        // 3. The request itself.
        $head = $readHead($client, $until);
        if ($head === null || preg_match('#^([A-Z]+) (\S+) HTTP/1\.[01]\r\n#', $head, $r) !== 1) {
            continue;
        }
        $method = $r[1];
        $path   = (string) parse_url($r[2], PHP_URL_PATH);
        $length = preg_match('/^Content-Length:\s*(\d+)\s*$/mi', $head, $l) === 1 ? (int) $l[1] : 0;
        if (preg_match('/^Expect:\s*100-continue\s*$/mi', $head) === 1) {
            // libcurl asks before sending a body over 1 KB; without this it
            // waits a second and sends anyway. Answering keeps a test quick.
            fwrite($client, "HTTP/1.1 100 Continue\r\n\r\n");
        }
        $got = 0;
        while ($got < $length && microtime(true) < $until) {
            $piece = fread($client, min(65536, $length - $got));
            if ($piece === false || ($piece === '' && feof($client) === true)) {
                break;
            }
            $got += strlen($piece);
        }

        // 4. The answer the test asked for, read afresh for every request.
        $answers = json_decode((string) @file_get_contents($dir . DIRECTORY_SEPARATOR . 'answers.json'), true);
        $chosen  = null;
        foreach ((is_array($answers) === true ? (array) ($answers[$host] ?? []) : []) as $entry) {
            if (is_array($entry) === true && str_starts_with($path, (string) ($entry['path'] ?? "\0")) === true) {
                $chosen = $entry;
                break;
            }
        }
        if (is_array($chosen) === true && ($chosen['drop'] ?? false) === true) {
            // 🌐 A NETWORK FAILURE (#1043): the request is read and then the
            //    connection is closed with no answer at all, as when a service
            //    or the network between hangs up. libcurl then reports "Empty
            //    reply from server" and curl_exec() answers false - the one
            //    road a status code cannot reach. Status 0 in the log.
            $note(['host' => $host, 'method' => $method, 'path' => $path, 'status' => 0, 'bytes' => $got]);
            continue;
        }
        $status = $chosen === null ? 404 : (int) ($chosen['status'] ?? 200);
        $body   = $chosen === null ? '' : (array_key_exists('json', $chosen) === true ? (string) json_encode($chosen['json']) : (string) ($chosen['body'] ?? ''));
        fwrite($client, 'HTTP/1.1 ' . $status . " Stand-in\r\nContent-Type: " . (array_key_exists('json', (array) $chosen) === true ? 'application/json' : 'text/plain')
            . "\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        $note(['host' => $host, 'method' => $method, 'path' => $path, 'status' => $status, 'bytes' => $got]);
    } finally {
        @fclose($client);
    }
}
@fclose($listener);
@unlink($dir . DIRECTORY_SEPARATOR . 'server.pem');
exit(0);
