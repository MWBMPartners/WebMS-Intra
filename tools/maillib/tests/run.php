<?php
// Path: tools/maillib/tests/run.php
/**
 * -----------------------------------------------------------------------------
 * The mail library's own test 🧪
 * -----------------------------------------------------------------------------
 * Proves the mail library (`web/_core/maillib/`) against stand-in mail servers
 * on 127.0.0.1. It needs PHP with OpenSSL, and Python 3 for one independent
 * check. It needs no database, no web server and no file of any application:
 * the library is tested the way it will be used once lifted out. Everything it
 * needs is in this folder and in the library's own: copy those two folders
 * anywhere and the whole test runs.
 *
 *   php tools/maillib/tests/run.php                 the whole test
 *   php tools/maillib/tests/run.php --only=L9,L12   only proofs whose name starts so
 *   php tools/maillib/tests/run.php --scratch=DIR   use this (new) folder for scratch files
 *   php tools/maillib/tests/run.php --keep          keep the scratch folder (keys are still removed)
 *   php tools/maillib/tests/run.php --no-python     leave out the Python check, and FAIL saying so
 *
 * NOTHING REAL IS CONTACTED. Every connection goes to 127.0.0.1, to a server
 * this test started and stops.
 *
 * HOW IT IS BUILT, and why it is one file playing two parts:
 *   1. THE ORGANISER (this file as you start it). Makes a scratch folder and
 *      the test's own certificates in it, starts `smtp-standin.php`, runs part
 *      2, runs `message-check.py` and the stand-alone check, stops the
 *      stand-in by its process number, checks nothing is left listening, and
 *      removes the scratch folder.
 *   2. THE PROOFS (this file started again with `--cases`). Runs the library.
 *      🔒 The organiser ALWAYS starts this part with PHP's `disable_functions`
 *      set to proc_open, popen, exec, shell_exec, system, passthru, mail and
 *      fsockopen. A shared host may switch all of those off, so the library is
 *      never tested with them available. (Only the organiser, which is test
 *      scaffolding and not the library, starts other programs.)
 *   3. PROOFS THAT NEED A PHP IN A PARTICULAR STATE, each group started the
 *      same way as part 2, in a PHP of its own:
 *        - `--fresh-process`: with `idn_to_ascii` switched off as well. That is
 *          PHP on a host without the "intl" extension, where a non-English
 *          domain cannot be converted and must be refused. And before any
 *          Secret has been made in that process, which is the only state in
 *          which one of the faults it guards against ever showed.
 *        - `--small-memory`: with PHP's memory limited to 32 MB, where a 10 MB
 *          attachment must be refused in words and not end in a fatal error.
 *        - `--low-floor`: with OpenSSL told, for that one process only (through
 *          the `OPENSSL_CONF` setting and a file in the scratch folder), that
 *          it MAY speak the old TLS 1.1. See "L17e" below for why.
 *   4. `stands-alone.php`, the check that the library reaches for nothing
 *      outside itself, run over the library and over its own planted files.
 *
 * NO KEY IS EVER COMMITTED. The certificates are made fresh for each run, in
 * PHP, by writing the certificate's bytes by hand (so that an expired one and
 * a not-yet-valid one can be made: PHP's own certificate-signing function
 * always starts a certificate "now"). The private keys never leave the scratch
 * folder and are deleted with it.
 *
 * A CRASH MUST NOT LOOK LIKE A PASS. The proofs end by printing how many ran;
 * the organiser refuses the run unless that line is there AND the number is
 * the number expected (`EXPECTED_PROOFS`). The last line of a good run is
 * "the run reached its end: N of N proofs passed".
 *
 * WHAT THIS TEST CANNOT SHOW (said here so nobody reads a pass as more):
 *   - Nothing about a real mail server, a real network or a hosting company.
 *   - The limit on CONNECTING is PHP's own and is not proved: a server that
 *     accepts a connection and then says nothing is proved, but a server that
 *     never answers the first knock cannot be staged on 127.0.0.1.
 *   - "TLS 1.2 or later" is kept by two locks in the library: the method it
 *     asks PHP for, and its own look at what was agreed. Proof L17e shows the
 *     first one live (with OpenSSL's own floor lowered, a TLS 1.1 server is
 *     still refused before any handshake completes). The second cannot be
 *     shown alone: while the first holds, no TLS 1.1 handshake ever completes
 *     for it to refuse.
 *   - A machine whose OpenSSL is set to accept 1024-bit keys or SHA-1
 *     signatures (below "security level 2") fails proof L6w, which needs
 *     such a certificate to be refused before it can check the reason given.
 *
 * @package   MWBM\Mail\Tests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

use MWBM\Mail\CertificateFacts;
use MWBM\Mail\Client;
use MWBM\Mail\Clock;
use MWBM\Mail\LogSink;
use MWBM\Mail\Message;
use MWBM\Mail\MessageRefused;
use MWBM\Mail\Secret;
use MWBM\Mail\SendOptions;
use MWBM\Mail\SendResult;
use MWBM\Mail\Smtp\CertificateReader;
use MWBM\Mail\Smtp\Connection;
use MWBM\Mail\Smtp\SmtpConfig;
use MWBM\Mail\Smtp\SmtpTransport;
use MWBM\Mail\SystemClock;
use MWBM\Mail\Transport;

/** The functions a shared host may switch off. The library is always run without them. */
const DISABLED_FOR_THE_LIBRARY = 'proc_open,popen,exec,shell_exec,system,passthru,mail,fsockopen';

/** How many proofs a whole run holds. A run with any other number has not finished properly. */
const EXPECTED_PROOFS = 74;

/** The sign-in every stand-in server accepts. The password is made up and easy to spot in a leak. */
const TEST_USER = 'mailbox-user';
const TEST_PASS = 'Pa55-ZQX-do-not-print-7781';
const WRONG_PASS = 'Wr0ng-KJV-also-never-shown-4409';

/** Extra seconds a timed step may take beyond its limit before the proof fails. Generous, for a busy machine. */
const SLACK = 8.0;

if (PHP_SAPI !== 'cli') {
    exit(2);
}
// The library's loader is the ONLY file of the library, or of anything else, that this test requires.
require dirname(__DIR__, 3) . '/web/_core/maillib/autoload.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--([a-z-]+)(?:=(.*))?\z/s', $argument, $found) === 1) {
        $options[$found[1]] = $found[2] ?? '1';
    }
}
// The choice between the two parts is made at the very END of this file: PHP
// has to have read the classes below (which build on the library's own) first.

// =============================================================================
// 🗂️ PART 1 — THE ORGANISER
// =============================================================================

/** @param array<string,string> $options */
function organise(array $options): int
{
    $only = $options['only'] ?? '';
    $scratch = $options['scratch'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'maillib-test-' . bin2hex(random_bytes(6));
    if (file_exists($scratch) === true) {
        fwrite(STDERR, "The scratch folder already exists; give a new one: $scratch\n");
        return 2;
    }
    foreach (['', '/certs', '/log', '/bodies', '/expect'] as $sub) {
        mkdir($scratch . $sub, 0700, true);
    }
    echo "Mail library test. PHP " . PHP_VERSION . ", " . OPENSSL_VERSION_TEXT . ". Scratch folder: $scratch\n";

    makeCertificates($scratch . '/certs');
    file_put_contents($scratch . '/scenarios.ser', serialize(scenarios()));
    // For proof L17e only: settings that let OpenSSL speak TLS 1.0 and 1.1
    // again. Used by ONE process, through its environment; nothing else on
    // the machine sees this file.
    file_put_contents($scratch . '/openssl-low.cnf', "openssl_conf = default_conf\n[default_conf]\nssl_conf = ssl_sect\n"
        . "[ssl_sect]\nsystem_default = system_default_sect\n[system_default_sect]\nMinProtocol = TLSv1\nCipherString = DEFAULT:@SECLEVEL=0\n");

    // 🎭 Start the stand-in servers and wait for them to say which ports they took.
    // `--watch-parent`: the pipe to its standard input stays open for as long
    // as this organiser lives. If the organiser is killed, the stand-in sees
    // the pipe close, deletes the keys and the scratch folder, and stops.
    $standin = proc_open(
        [PHP_BINARY, __DIR__ . '/smtp-standin.php', $scratch, '--watch-parent'],
        [0 => ['pipe', 'r'], 1 => ['file', $scratch . '/standin.out', 'w'], 2 => ['file', $scratch . '/standin.err', 'w']],
        $pipes,
    );
    $standinPid = is_resource($standin) === true ? (int) proc_get_status($standin)['pid'] : 0;
    for ($waited = 0; $waited < 300 && file_exists($scratch . '/ports.ser') !== true; $waited++) {
        usleep(100000);
    }
    $passed = 0;
    $failed = 0;
    $note = static function (bool $ok, string $id, string $title, string $detail = '') use (&$passed, &$failed): void {
        $ok === true ? $passed++ : $failed++;
        echo ($ok === true ? 'PASS  ' : 'FAIL  ') . str_pad($id, 13) . $title . ($detail === '' ? '' : "\n        " . $detail) . "\n";
    };

    $ports = [];
    if (file_exists($scratch . '/ports.ser') !== true) {
        echo "FAIL  the stand-in servers did not start: " . trim((string) @file_get_contents($scratch . '/standin.err')) . "\n";
        $failed++;
    } else {
        $ports = (array) unserialize((string) file_get_contents($scratch . '/ports.ser'), ['allowed_classes' => false]);
        echo 'Stand-in servers started as process ' . $standinPid . ', ' . count($ports) . " scenarios, 127.0.0.1 only.\n\n";

        // 🧪 The proofs, in a second PHP with the shell functions switched off.
        $command = [
            PHP_BINARY,
            '-d', 'disable_functions=' . DISABLED_FOR_THE_LIBRARY,
            // Stack traces must carry their arguments here, or the proof that
            // a password never appears in one would be looking at nothing.
            '-d', 'zend.exception_ignore_args=0',
            '-d', 'memory_limit=512M',
            '-d', 'display_errors=1',
            '-d', 'error_reporting=-1',
            __FILE__, '--cases', '--scratch=' . $scratch,
        ];
        if ($only !== '') {
            $command[] = '--only=' . $only;
        }
        [$code, $output] = runAndShow($command);
        if (preg_match('/^the proofs reached their end: (\d+) ran, (\d+) passed, (\d+) failed$/m', $output, $found) === 1) {
            $passed += (int) $found[2];
            $failed += (int) $found[3];
            if ($code !== ((int) $found[3] === 0 ? 0 : 1)) {
                $note(false, '-', 'The proofs ended with exit code ' . $code . ', which does not match what they printed');
            }
        } else {
            $note(false, '-', 'The proofs did NOT reach their end (exit code ' . $code . '). A crash is not a pass.');
        }

        // 🌱 Proofs that need a PHP in a state of its own. Each entry: the
        // proofs it holds, the flag that selects them, what to change in the
        // command above (by position), and what to add to the environment.
        $groups = [
            [['X8', 'X9'], '--fresh-process', [2 => 'disable_functions=' . DISABLED_FOR_THE_LIBRARY . ',idn_to_ascii'], []],
            [['X11'], '--small-memory', [6 => 'memory_limit=32M'], []],
            [['L17e'], '--low-floor', [], ['OPENSSL_CONF' => $scratch . '/openssl-low.cnf']],
        ];
        foreach ($groups as [$ids, $flag, $changes, $environment]) {
            $wanted = $only === '';
            foreach (explode(',', $only) as $prefix) {
                foreach ($ids as $id) {
                    $wanted = $wanted === true || ($prefix !== '' && strncmp($id, $prefix, strlen($prefix)) === 0);
                }
            }
            if ($wanted !== true) {
                continue;
            }
            $own = array_replace($command, $changes);
            $own[] = $flag;
            [$code, $ownOutput] = runAndShow($own, $environment);
            if (preg_match('/^the proofs reached their end: (\d+) ran, (\d+) passed, (\d+) failed$/m', $ownOutput, $found) === 1) {
                $passed += (int) $found[2];
                $failed += (int) $found[3];
            } else {
                // This is also what a fatal error looks like (PHP out of
                // memory, for one): the closing line is never printed.
                $note(false, implode('-', $ids), 'The proofs that need a PHP of their own (' . $flag . ') did NOT reach their end (exit code ' . $code . '). A crash is not a pass.');
            }
            $output .= $ownOutput;
        }

        // 🔒 L12e: everything the proofs printed, searched for the password.
        if ($only === '' || strncmp('L12', $only, 3) === 0) {
            $found = findSecret($output);
            $note($found === '', 'L12e', 'Nothing the proofs printed contains the password in any form', $found);
        }

        // 📨 L1c: every message a stand-in server received, read back by Python's own e-mail parser.
        $waiting = glob($scratch . '/expect/*', GLOB_ONLYDIR);
        if ($only === '' || (is_array($waiting) === true && $waiting !== [])) {
            if (isset($options['no-python']) === true) {
                $note(false, 'L1c', 'NOT RUN: the Python check was left out with --no-python, so no message was read back independently');
            } else {
                [$code, $output] = runAndShow(['python3', __DIR__ . '/message-check.py', $scratch]);
                $reached = preg_match('/^the message check reached its end: (\d+) messages, (\d+) checks, (\d+) failed$/m', $output, $found) === 1;
                $note(
                    $reached === true && $code === 0 && (int) $found[3] === 0 && (int) $found[1] > 0,
                    'L1c',
                    "Python's own parser reads every received message back, byte for byte",
                    $reached === true ? $found[1] . ' messages, ' . $found[2] . ' checks, ' . $found[3] . ' failed' : 'the check did not reach its end',
                );
            }
        }

        // 🧱 L18b: the check that the library reaches for nothing outside itself.
        // It is PHP and lives in this folder with its planted files, so it
        // runs wherever the test does. (It used to be a Python script in the
        // portal's own tools folder: a copy of the library's two folders
        // could not run it, and neither could a machine without Python.)
        if ($only === '' || strncmp('L18', $only, 3) === 0) {
            [$code, $output] = runAndShow([PHP_BINARY, __DIR__ . '/stands-alone.php']);
            $note(
                $code === 0 && strpos($output, 'the check reached its end') !== false,
                'L18b',
                'The stand-alone check finds the library reaching for nothing outside itself, and fires on each planted file',
                'exit code ' . $code,
            );
        }
    }

    // 🧹 Stop the stand-in: ask it nicely, then by its own process number. Never by name.
    file_put_contents($scratch . '/stop', '1');
    $stopped = false;
    for ($waited = 0; $waited < 100 && is_resource($standin) === true; $waited++) {
        if (proc_get_status($standin)['running'] !== true) {
            $stopped = true;
            break;
        }
        usleep(100000);
    }
    if ($stopped !== true && is_resource($standin) === true) {
        proc_terminate($standin);
        usleep(500000);
        $stopped = proc_get_status($standin)['running'] !== true;
    }
    if (is_resource($standin) === true) {
        proc_close($standin);
    }
    $listening = 0;
    foreach ($ports as $port) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorNumber, $errorText, 1.0);
        if (is_resource($probe) === true) {
            $listening++;
            fclose($probe);
        }
    }
    $standinErrors = trim((string) @file_get_contents($scratch . '/standin.err'));
    if ($only === '') {
        $note(
            $stopped === true && $listening === 0 && $standinErrors === '',
            'X6',
            'The stand-in servers were stopped (process ' . $standinPid . ') and nothing is left listening',
            ($listening === 0 ? '' : $listening . ' ports still answer. ') . ($standinErrors === '' ? '' : 'The stand-in said: ' . $standinErrors),
        );
    }

    // 🔑 The private keys go first and always; the rest goes unless --keep was given.
    $keys = glob($scratch . '/certs/*.key');
    foreach (is_array($keys) === true ? $keys : [] as $key) {
        unlink($key);
    }
    if (isset($options['keep']) === true) {
        echo "The scratch folder was kept (its private keys were removed): $scratch\n";
    } else {
        removeFolder($scratch);
    }
    if ($only === '') {
        $note(file_exists($scratch . '/certs/leaf.key') !== true, 'X7', 'The test\'s private keys were removed');
    }

    $total = $passed + $failed;
    echo "\n";
    if ($only !== '') {
        echo "PARTIAL RUN (--only=$only): $passed of $total proofs passed. This is not the whole test.\n";
        return $failed === 0 ? 0 : 1;
    }
    if ($total !== EXPECTED_PROOFS) {
        echo "FAILED: $total proofs ran, but a whole run has " . EXPECTED_PROOFS . ". Something did not run.\n";
        return 1;
    }
    echo 'the run reached its end: ' . $passed . ' of ' . $total . ' proofs passed' . ($failed === 0 ? '' : ', ' . $failed . ' FAILED') . "\n";
    return $failed === 0 ? 0 : 1;
}

/**
 * Run a program, showing what it prints as it prints it, and return its exit
 * code and everything it printed.
 *
 * @param list<string>         $command
 * @param array<string,string> $environment Added to this process's own environment, for that program only.
 *
 * @return array{0:int,1:string}
 */
function runAndShow(array $command, array $environment = []): array
{
    $process = @proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $environment === [] ? null : array_merge(getenv(), $environment),
    );
    if (is_resource($process) !== true) {
        return [127, 'could not start ' . $command[0]];
    }
    fclose($pipes[0]);
    $output = '';
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    while ($open !== []) {
        $read = array_values($open);
        $write = null;
        $except = null;
        if ((int) stream_select($read, $write, $except, 1) < 1) {
            continue;
        }
        foreach ($read as $pipe) {
            $chunk = fread($pipe, 65536);
            if ($chunk === '' || $chunk === false) {
                if (feof($pipe) === true) {
                    unset($open[(int) array_search($pipe, $open, true)]);
                }
                continue;
            }
            echo $chunk;
            $output .= $chunk;
        }
    }
    return [proc_close($process), $output];
}

/** The password, or a piece of it, found in some text: a short description, or '' when clean. */
function findSecret(string $text): string
{
    foreach ([TEST_PASS, WRONG_PASS] as $secret) {
        $forms = [$secret, base64_encode($secret), base64_encode("\0" . TEST_USER . "\0" . $secret)];
        foreach ($forms as $number => $form) {
            // Any 10 characters in a row count, so a cut-off copy is caught too.
            // (For the third form the first 20 characters are the user name, which is no secret.)
            for ($start = $number === 2 ? 20 : 0; $start + 10 <= strlen($form); $start++) {
                $at = strpos($text, substr($form, $start, 10));
                if ($at !== false) {
                    return 'FOUND a piece of the password (form ' . ($number + 1) . ') near: "'
                        . addcslashes(substr($text, max(0, $at - 50), 50), "\0..\37") . '[...]"';
                }
            }
        }
    }
    return '';
}

function removeFolder(string $folder): void
{
    $items = scandir($folder);
    foreach (is_array($items) === true ? $items : [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $folder . DIRECTORY_SEPARATOR . $item;
        is_dir($path) === true && is_link($path) !== true ? removeFolder($path) : unlink($path);
    }
    rmdir($folder);
}

// -----------------------------------------------------------------------------
// 🪪 The test's own certificates, written byte by byte
// -----------------------------------------------------------------------------

/** One piece of a certificate in the standard's "tag, length, content" form (DER). */
function der(int $tag, string $content): string
{
    $length = strlen($content);
    if ($length < 128) {
        return chr($tag) . chr($length) . $content;
    }
    $bytes = ltrim(pack('N', $length), "\0");
    return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
}

/** An object identifier such as "2.5.29.17", in DER. */
function derOid(string $dotted): string
{
    $parts = array_map('intval', explode('.', $dotted));
    $encoded = chr($parts[0] * 40 + $parts[1]);
    foreach (array_slice($parts, 2) as $number) {
        $piece = chr($number & 0x7F);
        $number >>= 7;
        while ($number > 0) {
            $piece = chr(0x80 | ($number & 0x7F)) . $piece;
            $number >>= 7;
        }
        $encoded .= $piece;
    }
    return der(0x06, $encoded);
}

/**
 * Make one certificate.
 *
 * @param list<string> $dnsNames   Server names it is for.
 * @param list<string> $addresses  IP addresses it is for.
 * @param bool         $authority  True for a certificate authority's own certificate.
 * @param list<string> $odd        Anything of: 'client-only' (marked for use by a client, not a server),
 *                                 'sha1' (signed with the old SHA-1 method).
 */
function makeCertificate(
    string $subject,
    OpenSSLAsymmetricKey $subjectKey,
    string $issuer,
    OpenSSLAsymmetricKey $issuerKey,
    int $validFrom,
    int $validTo,
    array $dnsNames,
    array $addresses,
    bool $authority,
    array $odd = [],
): string {
    $name = static fn (string $commonName): string => der(0x30, der(0x31, der(0x30, derOid('2.5.4.3') . der(0x0C, $commonName))));
    $time = static fn (int $moment): string => der(0x17, gmdate('ymdHis', $moment) . 'Z');
    $publicKey = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($subjectKey)['key']));

    // "Basic constraints": may this certificate sign others?
    $extensions = der(0x30, derOid('2.5.29.19') . ($authority === true ? der(0x01, "\xFF") : '')
        . der(0x04, der(0x30, $authority === true ? der(0x01, "\xFF") : '')));
    // "Subject alternative name": the names and addresses it is for.
    $names = '';
    foreach ($dnsNames as $dnsName) {
        $names .= der(0x82, $dnsName);
    }
    foreach ($addresses as $address) {
        $names .= der(0x87, (string) inet_pton($address));
    }
    if ($names !== '') {
        $extensions .= der(0x30, derOid('2.5.29.17') . der(0x04, der(0x30, $names)));
    }

    if (in_array('client-only', $odd, true) === true) {
        // "Extended key usage": TLS client authentication only.
        $extensions .= der(0x30, derOid('2.5.29.37') . der(0x04, der(0x30, derOid('1.3.6.1.5.5.7.3.2'))));
    }

    $sha1 = in_array('sha1', $odd, true);
    $algorithm = der(0x30, derOid($sha1 === true ? '1.2.840.10045.4.1' : '1.2.840.10045.4.3.2')); // ECDSA with SHA-1, or with SHA-256
    $body = der(0x30, der(0xA0, der(0x02, "\x02")) . der(0x02, "\x01" . random_bytes(8)) . $algorithm . $name($issuer)
        . der(0x30, $time($validFrom) . $time($validTo)) . $name($subject) . $publicKey . der(0xA3, der(0x30, $extensions)));
    openssl_sign($body, $signature, $issuerKey, $sha1 === true ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
    $certificate = der(0x30, $body . $algorithm . der(0x03, "\x00" . $signature));
    return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certificate), 64, "\n") . "-----END CERTIFICATE-----\n";
}

/**
 * The authority the test trusts, and one certificate for each case.
 *
 * Every date is worked out from today, so the test cannot go stale: "expired"
 * ended a month ago whenever it is run, "notyet" starts a month from now.
 */
function makeCertificates(string $folder): void
{
    $newKey = static fn (): OpenSSLAsymmetricKey => openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $authorityKey = $newKey();
    $otherAuthorityKey = $newKey();
    $serverKey = $newKey();
    $now = time();
    $day = 86400; // used only to place test dates well clear of "now"; nothing counts days with it
    $right = [['localhost'], ['127.0.0.1']];

    openssl_pkey_export($serverKey, $pem);
    file_put_contents($folder . '/leaf.key', $pem);
    chmod($folder . '/leaf.key', 0600);

    $make = static function (string $file, string $issuer, OpenSSLAsymmetricKey $issuerKey, int $from, int $to, array $names) use ($folder, $serverKey): void {
        file_put_contents($folder . '/' . $file . '.pem', makeCertificate('standin.test', $serverKey, $issuer, $issuerKey, $from, $to, $names[0], $names[1], false));
    };
    file_put_contents($folder . '/ca.pem', makeCertificate('Mail library test authority', $authorityKey, 'Mail library test authority', $authorityKey, $now - $day, $now + 30 * $day, [], [], true));
    $make('good', 'Mail library test authority', $authorityKey, $now - $day, $now + 30 * $day, $right);
    $make('expired', 'Mail library test authority', $authorityKey, $now - 400 * $day, $now - 30 * $day, $right);
    $make('expired2', 'Mail library test authority', $authorityKey, $now - 400 * $day, $now - 30 * $day, $right);
    $make('notyet', 'Mail library test authority', $authorityKey, $now + 30 * $day, $now + 60 * $day, $right);
    $make('wrongname', 'Mail library test authority', $authorityKey, $now - $day, $now + 30 * $day, [['other.test', '*.wild.test'], []]);
    $make('selfsigned', 'standin.test', $serverKey, $now - $day, $now + 30 * $day, $right);
    $make('privateca', 'Some other authority', $otherAuthorityKey, $now - $day, $now + 30 * $day, $right);

    // 🪪 Certificates for the library's own name rule. Each is signed by the
    // trusted authority and in date, so the NAME is the only question. The
    // first argument after the file name is the "common name".
    $named = static function (string $file, string $commonName, array $dnsNames, array $addresses, array $odd = [], ?OpenSSLAsymmetricKey $key = null) use ($folder, $serverKey, $authorityKey, $now, $day): void {
        file_put_contents($folder . '/' . $file . '.pem', makeCertificate($commonName, $key ?? $serverKey, 'Mail library test authority', $authorityKey, $now - $day, $now + 30 * $day, $dnsNames, $addresses, false, $odd));
    };
    $named('name-list-other', 'localhost', ['other.test'], []);          // the list is for another server; the common name is right
    $named('name-list-address', 'localhost', [], ['127.0.0.1']);         // the list holds only an address; the common name is right
    $named('name-no-list', 'localhost', [], []);                          // no list at all: the common name counts
    $named('name-star-alone', 'standin.test', ['*'], []);                 // a bare star
    $named('name-star-inside', 'standin.test', ['loc*', '*host', 'lo*st', 'l*.localhost', 'localhost.*'], []);
    $named('name-address-as-name', 'standin.test', ['127.0.0.1'], []);    // an address written in the list as if it were a name
    // 🪪 Certificates that are in date, rightly named and signed by the
    // trusted authority, and refused all the same.
    $named('odd-client-only', 'standin.test', ['localhost'], ['127.0.0.1'], ['client-only']);
    $named('odd-sha1', 'standin.test', ['localhost'], ['127.0.0.1'], ['sha1']);
    $weakKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]);
    openssl_pkey_export($weakKey, $pem);
    file_put_contents($folder . '/weak.key', $pem);
    chmod($folder . '/weak.key', 0600);
    $named('odd-weak-key', 'standin.test', ['localhost'], ['127.0.0.1'], [], $weakKey);
}

// -----------------------------------------------------------------------------
// 🎭 What each stand-in server does (see smtp-standin.php for the words used)
// -----------------------------------------------------------------------------

/** @return array<string,array<string,mixed>> */
function scenarios(): array
{
    $ok = ['user' => TEST_USER, 'pass' => TEST_PASS];
    $says = static fn (string $step, string $reply): array => $ok + ['replies' => [$step => $reply]];
    return [
        // A server that accepts, in both kinds of encryption.
        'accept-starttls' => $ok,
        'accept-implicit' => $ok + ['tls' => 'implicit'],
        'headers' => $ok,
        'raw' => $ok,
        'reader-mode' => $ok + ['tls' => 'implicit'],
        // Signing in.
        'refuse-signin' => $ok,
        'login-only' => $ok + ['offersEncrypted' => ['AUTH LOGIN']],
        'no-signin-method' => $ok + ['offersEncrypted' => ['AUTH XOAUTH2 CRAM-MD5']],
        'echo' => $says('AUTH', '@echo'),
        'echo-login' => $ok + ['offersEncrypted' => ['AUTH LOGIN'], 'replies' => ['AUTH' => '@echo']],
        'traces' => $ok,
        // Certificates.
        'cert-expired' => $ok + ['certs' => ['expired']],
        'cert-expired-implicit' => $ok + ['certs' => ['expired'], 'tls' => 'implicit'],
        'cert-notyet' => $ok + ['certs' => ['notyet']],
        'cert-wrongname' => $ok + ['certs' => ['wrongname']],
        'cert-selfsigned' => $ok + ['certs' => ['selfsigned']],
        'cert-privateca' => $ok + ['certs' => ['privateca']],
        'cert-good-no-authority' => $ok,
        // Connections 1 to 3 show one expired certificate; from the 4th on, a different one.
        'pin' => $ok + ['certs' => ['expired', 'expired', 'expired', 'expired2']],
        'pin-implicit' => $ok + ['certs' => ['expired'], 'tls' => 'implicit'],
        // No encryption on offer.
        'no-starttls' => $ok + ['offers' => ['AUTH PLAIN LOGIN']],
        'no-starttls-pinned' => $ok + ['offers' => ['AUTH PLAIN LOGIN']],
        'starttls-refused' => $says('STARTTLS', '454 4.7.0 encryption is not available just now'),
        'plain-only' => $ok + ['tls' => 'none'],
        'unencrypted-guard' => $ok + ['tls' => 'none'],
        'inject' => $says('STARTTLS', "220 2.0.0 go ahead\r\n250-evil.test\r\n250 AUTH PLAIN LOGIN"),
        'old-tls' => $ok + ['oldTls' => true],
        'old-tls-pinned' => $ok + ['oldTls' => true, 'tls' => 'implicit'],
        // Servers that go silent.
        'stall-greeting' => $says('GREETING', '@silent'),
        'stall-handshake' => $ok + ['handshake' => 'stall'],
        'stall-handshake-implicit' => $ok + ['handshake' => 'stall', 'tls' => 'implicit'],
        'stall-signin' => $says('AUTH', '@silent'),
        'stall-command' => $says('RCPT', '@silent'),
        'stall-reading' => $says('BODY', '@stop-reading'),
        'stall-verdict' => $says('BODY', '@silent'),
        'slow' => $ok + ['delay' => 0.7],
        'drip' => $says('MAIL', '@drip:250 2.1.0 slowly'),
        // Servers that hang up.
        'drop-mid' => $says('BODY', '@drop-mid'),
        'drop-after' => $says('BODY', '@drop-after'),
        // Servers that say no, or later.
        'refusals' => $ok + [
            'mail' => ['blocked@standin.test' => '550 5.7.1 not allowed to send as that address', 'later@standin.test' => '451 4.3.0 try again in a while'],
            'rcpt' => ['nobody@standin.test' => '550 5.1.1 no such mailbox here', 'busy@standin.test' => '450 4.2.1 that mailbox is busy'],
        ],
        'greeting-no' => $says('GREETING', '554 5.3.2 no mail service here'),
        'greeting-closing' => $says('GREETING', '421 4.3.2 shutting down'),
        'data-later' => $says('DATA', '452 4.3.1 out of space for now'),
        'data-no' => $says('DATA', '554 5.5.0 no messages taken'),
        'body-later' => $says('BODY', '451 4.3.0 could not store it, try again'),
        'body-no' => $says('BODY', '552 5.3.4 that message is too big for me'),
        'body-closing' => $says('BODY', '421 4.3.2 closing, try again'),
        // Servers that answer with something that does not belong there.
        'odd-greeting' => $says('GREETING', 'hello, what do you want?'),
        'odd-ehlo' => $says('EHLO', '354 go ahead and type'),
        'odd-mixed' => $says('EHLO', "250-standin.test\r\n251 STARTTLS"),
        'odd-starttls' => $says('STARTTLS', '250 2.0.0 fine by me'),
        'odd-signin' => $says('AUTH', '250 2.0.0 welcome anyway'),
        'odd-sender' => $says('MAIL', '354 keep typing'),
        'odd-recipient' => $says('RCPT', '220 hello again'),
        'odd-data' => $says('DATA', '250 2.0.0 done already'),
        'odd-verdict' => $says('BODY', 'thanks, got it'),
        'odd-verdict-number' => $says('BODY', '252 2.0.0 perhaps'),
        // One connection, many messages.
        'twenty' => $ok,
        'closes-between' => $ok + ['closeAfterMessages' => 1],
        'dead' => $says('GREETING', '@silent'),
        'three-each' => $ok,
        'second-route' => $ok,
        // Size.
        'small-limit' => $ok + ['offersEncrypted' => ['AUTH PLAIN LOGIN', 'SIZE 2500']],
        // The library's own name rule (the settings name the server "localhost" for these).
        'name-list-other' => $ok + ['certs' => ['name-list-other']],
        'name-list-address' => $ok + ['certs' => ['name-list-address']],
        'name-no-list' => $ok + ['certs' => ['name-no-list']],
        'name-star-alone' => $ok + ['certs' => ['name-star-alone']],
        'name-star-inside' => $ok + ['certs' => ['name-star-inside']],
        'name-address-as-name' => $ok + ['certs' => ['name-address-as-name']],
        // Refused for a reason of their own.
        'odd-client-only' => $ok + ['certs' => ['odd-client-only']],
        'odd-sha1' => $ok + ['certs' => ['odd-sha1'], 'lowSecurity' => true],
        'odd-weak-key' => $ok + ['certs' => ['odd-weak-key'], 'key' => 'weak', 'lowSecurity' => true],
        // A handshake that fails for a reason that is NOT the certificate.
        'cut-first' => $ok + ['handshake' => 'cut-first'],
        'cut-first-implicit' => $ok + ['handshake' => 'cut-first', 'tls' => 'implicit'],
        'alert-first' => $ok + ['handshake' => 'alert-first'],
        'cut-first-expired' => $ok + ['handshake' => 'cut-first', 'certs' => ['expired']],
        'expired-unreadable' => $ok + ['certs' => ['expired'], 'maxConnections' => 1],
        // Old TLS, for the PHP whose OpenSSL has been told it may speak it (proof L17e).
        'old-tls-control' => $ok + ['oldTls' => true, 'tls' => 'implicit'],
        'old-tls-low' => $ok + ['oldTls' => true],
        'old-tls-low-pinned' => $ok + ['oldTls' => true, 'tls' => 'implicit'],
        'old-tls-low-reader' => $ok + ['oldTls' => true, 'tls' => 'implicit'],
        // A server that speaks when it should be listening.
        'early-verdict' => $ok + ['replies' => ['DATA' => "354 go ahead\r\n250 2.0.0 said too early", 'BODY' => '554 5.7.1 rejected after reading it']],
        'out-of-turn' => $says('MAIL', "250 2.1.0 sender ok\r\n250 2.1.5 an answer to a question nobody asked"),
        'refuse-mid' => $says('BODY', '@refuse-mid:552 5.3.4 that message is too big for this mailbox'),
        'long-line' => $says('GREETING', '220 ' . str_repeat('x', 6000)),
        'speaks-early' => $says('BODY', '@early:250 2.0.0 said before the message had all arrived'),
        // The password repeated back where a careless client would not look for it.
        'echo-recipient' => $ok + [
            'rcpt' => ['nobody@standin.test' => '550 5.1.1 no such mailbox; you signed in with ' . TEST_PASS . ' and ' . base64_encode("\0" . TEST_USER . "\0" . TEST_PASS)],
            'offersEncrypted' => ['AUTH PLAIN LOGIN', 'X-ECHO ' . TEST_PASS],
        ],
        // Faults inside the client itself.
        'after-handover' => $ok,
        'fault-words' => $ok,
        'close-clock' => $ok,
        'small-memory' => $ok,
    ];
}

// =============================================================================
// 🧪 PART 2 — THE PROOFS (run with the shell functions switched off)
// =============================================================================

/** Collects what the library reports to an application's log. */
final class MemoryLog implements LogSink
{
    /** @var list<string> */
    public array $lines = [];

    public function record(string $outcome, string $stage, string $words, array $facts = []): void
    {
        $this->lines[] = $outcome . ' ' . $stage . ' ' . $words . ' ' . print_r($facts, true);
    }
}

/**
 * A clock that looks at who is asking. Each time the library reads the timer,
 * it notes the arguments of every function on the way down to that call, which
 * is exactly what an error's stack trace would show. Optionally it breaks, once,
 * in the middle of the sign-in.
 */
final class SpyClock implements Clock
{
    public string $seen = '';
    public bool $broke = false;

    public function __construct(private readonly bool $breakDuringSignIn)
    {
    }

    public function now(): int
    {
        return time();
    }

    public function ticks(): float
    {
        // Only the library's own functions: the frames of this test hold the
        // test's workbench, which holds megabytes of collected output.
        $trace = array_values(array_filter(debug_backtrace(0), static fn (array $frame): bool => strncmp($frame['class'] ?? '', 'MWBM\\Mail\\', 10) === 0));
        $this->seen .= print_r(array_column($trace, 'args'), true);
        if ($this->breakDuringSignIn === true && $this->broke !== true && in_array('secretCommand', array_column($trace, 'function'), true) === true) {
            $this->broke = true;
            $fault = new RuntimeException('the clock broke on purpose');
            // The exception's own trace as text: PHP writes each argument in it, cut to 15 characters.
            $this->seen .= $fault->getTraceAsString();
            throw $fault;
        }
        return hrtime(true) / 1000000000;
    }
}

/**
 * A clock that breaks at a chosen moment, to stand for any fault inside the
 * client itself (the application hands the clock in, so it CAN be broken).
 *   'after-the-message'  the first reading taken once the whole message has
 *                        been written to the server
 *   'during-sign-in'     the first reading taken while a sign-in line is going
 *   'when-armed'         every reading, once `$armed` is set
 */
final class BreakingClock implements Clock
{
    public bool $broke = false;
    public bool $armed = false;
    private bool $sawTheMessage = false;

    public function __construct(private readonly string $when, private readonly string $says = 'the clock broke on purpose')
    {
    }

    public function now(): int
    {
        return time();
    }

    public function ticks(): float
    {
        $functions = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
        $inTheMessage = in_array('sendBody', $functions, true);
        $now = match ($this->when) {
            'after-the-message' => $this->sawTheMessage === true && $inTheMessage !== true && $this->broke !== true,
            'during-sign-in' => in_array('secretCommand', $functions, true) === true && $this->broke !== true,
            'when-armed' => $this->armed,
            default => false,
        };
        $this->sawTheMessage = $this->sawTheMessage === true || $inTheMessage === true;
        if ($now === true) {
            $this->broke = true;
            throw new RuntimeException($this->says);
        }
        return hrtime(true) / 1000000000;
    }
}

/** The proofs' workbench: builds settings and messages, sends, reads the stand-ins' logs, keeps score. */
final class Bench
{
    /** @var array<string,int> */
    public array $ports;
    public string $dir;
    public int $now;
    public MemoryLog $log;

    /** Everything the library returned, threw, logged or kept, for the password search at the end. */
    public string $haystack = '';

    public int $ran = 0;
    public int $passed = 0;
    private string $only;
    /** @var list<string> */
    private array $problems = [];
    /** @var list<object> Settings and routes made in the proof under way, dumped into the haystack when it ends. */
    private array $made = [];
    /** @var list<string> Measurements and counts to print under the proof's result. */
    private array $notes = [];

    /** @param array<string,string> $options */
    public function __construct(array $options)
    {
        $this->dir = $options['scratch'] ?? '';
        $this->only = $options['only'] ?? '';
        $this->ports = (array) unserialize((string) file_get_contents($this->dir . '/ports.ser'), ['allowed_classes' => false]);
        $this->now = time();
        $this->log = new MemoryLog();
    }

    public function proof(string $id, string $title, callable $body): void
    {
        if ($this->only !== '') {
            $wanted = false;
            foreach (explode(',', $this->only) as $prefix) {
                $wanted = $wanted === true || ($prefix !== '' && strncmp($id, $prefix, strlen($prefix)) === 0);
            }
            if ($wanted !== true) {
                return;
            }
        }
        // 🧹 If the organiser was killed, the stand-in has removed the scratch
        // folder. Stop here: carrying on would only time out against servers
        // that are gone.
        if (is_dir($this->dir . '/log') !== true) {
            echo "The scratch folder has gone, so the organiser was stopped. The proofs stop here.\n";
            exit(3);
        }
        $this->problems = [];
        $this->made = [];
        $this->notes = [];
        $started = microtime(true);
        ob_start();
        try {
            $body();
        } catch (Throwable $fault) {
            $this->problems[] = 'the proof itself stopped: ' . get_class($fault) . ': ' . $fault->getMessage() . ' (line ' . $fault->getLine() . ')';
            $this->haystack .= $fault->getTraceAsString() . print_r($fault->getTrace(), true);
        }
        $this->haystack .= (string) ob_get_clean();
        foreach ($this->made as $thing) {
            if ($thing instanceof Transport) {
                $thing->close();
            }
            $this->haystack .= dumpEveryWay($thing);
        }
        $this->haystack .= implode("\n", $this->log->lines);
        $this->ran++;
        $ok = $this->problems === [];
        $this->passed += $ok === true ? 1 : 0;
        echo ($ok === true ? 'PASS  ' : 'FAIL  ') . str_pad($id, 13) . $title . sprintf(' (%.1fs)', microtime(true) - $started) . "\n";
        foreach ($this->notes as $note) {
            echo '        ' . $note . "\n";
        }
        foreach ($this->problems as $problem) {
            echo '        - ' . $problem . "\n";
        }
    }

    /** Something worth printing under the proof's result. (Anything a proof echoes is captured for the password search.) */
    public function say(string $note): void
    {
        $this->notes[] = $note;
    }

    /** True when every proof is being run, not a chosen few. */
    public function isWholeRun(): bool
    {
        return $this->only === '';
    }

    public function check(bool $ok, string $what): void
    {
        if ($ok !== true) {
            $this->problems[] = $what;
        }
    }

    /** A timed step must end no sooner than its limit and not much later. Says what it measured. */
    public function within(float $seconds, float $limit, string $what): void
    {
        $this->check(
            $seconds >= $limit - 0.3 && $seconds <= $limit + SLACK,
            sprintf('%s: the limit was %.1fs and it took %.1fs (allowed: %.1fs to %.1fs)', $what, $limit, $seconds, $limit - 0.3, $limit + SLACK),
        );
        $this->say(sprintf('measured: %s ended after %.2fs against a limit of %.1fs', $what, $seconds, $limit));
    }

    /**
     * Settings for one stand-in server. Every limit is generous unless a proof narrows it.
     *
     * @param array<string,mixed> $over
     */
    public function config(string $scenario, array $over = []): SmtpConfig
    {
        $password = new Secret((string) ($over['password'] ?? TEST_PASS));
        unset($over['password']);
        $config = new SmtpConfig(...array_merge([
            'host' => '127.0.0.1',
            'port' => $this->ports[$scenario] ?? 1,
            'security' => SmtpConfig::STARTTLS,
            'username' => TEST_USER,
            'password' => $password,
            'caFile' => $this->dir . '/certs/ca.pem',
            'clientName' => 'client.test',
            'connectSeconds' => 15.0,
            'handshakeSeconds' => 15.0,
            'replySeconds' => 15.0,
            'writeSeconds' => 15.0,
            'bodyReplySeconds' => 15.0,
            'messageSeconds' => 30.0,
        ], $over));
        $this->made[] = $config;
        return $config;
    }

    /** @param array<string,mixed> $over */
    public function transport(string $scenario, array $over = [], ?Clock $clock = null): SmtpTransport
    {
        $transport = new SmtpTransport($this->config($scenario, $over), $clock, $this->log);
        $this->made[] = $transport;
        return $transport;
    }

    /**
     * Send, timing it, and keep a copy of the result for the password search.
     *
     * @return array{0:SendResult,1:float}
     */
    public function send(Transport|Client $through, Message $message, ?SendOptions $options = null): array
    {
        $started = microtime(true);
        $result = $through->send($message, $options);
        $seconds = microtime(true) - $started;
        $this->haystack .= dumpEveryWay($result);
        return [$result, $seconds];
    }

    /** A result must be exactly this outcome, stage and reason (and reply number, when given). */
    public function expect(SendResult $result, string $outcome, string $stage, string $reason, ?int $code = null, string $what = ''): void
    {
        $got = $result->outcome . ' / ' . $result->stage . ' / ' . $result->reason . ' / ' . $result->code;
        $this->check(
            $result->outcome === $outcome && $result->stage === $stage && $result->reason === $reason && ($code === null || $result->code === $code),
            trim($what . ' expected ' . $outcome . ' / ' . $stage . ' / ' . $reason . ($code === null ? '' : ' / ' . $code) . ', got ' . $got . ' ("' . $result->serverWords . '")'),
        );
    }

    /**
     * What a stand-in server saw, as a list of [connection, encrypted, kind, rest].
     * Waits until every connection it took has ended, so that "it saw no
     * sign-in" is said about a finished conversation and not a moment too soon.
     *
     * @return list<array{0:int,1:int,2:string,3:string}>
     */
    public function saw(string $scenario, bool $waitUntilClosed = true): array
    {
        $file = $this->dir . '/log/' . $scenario . '.log';
        $events = [];
        for ($tries = 0; $tries < 150; $tries++) {
            $events = [];
            $open = 0;
            foreach (file_exists($file) === true ? (array) file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
                $parts = explode(' ', (string) $line, 4);
                $events[] = [(int) $parts[0], (int) ($parts[1] ?? 0), $parts[2] ?? '', stripcslashes($parts[3] ?? '')];
                $open += ($parts[2] ?? '') === 'connect' ? 1 : (($parts[2] ?? '') === 'close' ? -1 : 0);
            }
            if ($waitUntilClosed !== true || $open <= 0) {
                break;
            }
            usleep(100000);
        }
        return $events;
    }

    /**
     * The same, as short lines that are easy to compare: "1 line MAIL FROM:<a@b.test>".
     * The leading 1 or 0 says whether encryption was on. `connect` and `close` are left out.
     *
     * @return list<string>
     */
    public function story(string $scenario, ?int $connection = null, bool $waitUntilClosed = true): array
    {
        $story = [];
        foreach ($this->saw($scenario, $waitUntilClosed) as [$number, $encrypted, $kind, $rest]) {
            if (in_array($kind, ['connect', 'close', 'note'], true) === true || ($connection !== null && $number !== $connection)) {
                continue;
            }
            if ($kind === 'tls') {
                $rest = (string) strtok($rest, ' ');
            } elseif ($kind === 'body') {
                $rest = preg_match('/complete=\d/', $rest, $found) === 1 ? $found[0] : $rest;
            }
            $story[] = $encrypted . ' ' . $kind . ' ' . $rest;
        }
        return $story;
    }

    /** How many times a kind of event appears in a server's log (optionally only where the rest starts so). */
    public function count(string $scenario, string $kind, string $startsWith = '', bool $waitUntilClosed = true): int
    {
        $count = 0;
        foreach ($this->saw($scenario, $waitUntilClosed) as [, , $eventKind, $rest]) {
            $count += $eventKind === $kind && ($startsWith === '' || strncmp($rest, $startsWith, strlen($startsWith)) === 0) ? 1 : 0;
        }
        return $count;
    }

    /** The file holding the last message a server received, relative to the scratch folder. */
    public function lastBodyFile(string $scenario): string
    {
        $file = '';
        foreach ($this->saw($scenario) as [, , $kind, $rest]) {
            if ($kind === 'body' && preg_match('/file=(\S+)/', $rest, $found) === 1) {
                $file = $found[1];
            }
        }
        return $file;
    }

    /**
     * Write down what a received message SHOULD contain, as plain files, for
     * `message-check.py` to compare with what Python's parser finds. Files of
     * bytes, not a structured format, so that nothing has to be encoded and
     * decoded on the way and "byte for byte" means exactly that.
     *
     * @param array<string,mixed> $spec The values the message was built from.
     */
    public function writeExpectation(string $id, array $spec, Message $message, string $bodyFile): void
    {
        $folder = $this->dir . '/expect/' . $id;
        // Not "and any folder above it": if the scratch folder has been
        // removed, nothing here may quietly make it again.
        mkdir($folder, 0700);
        $crlf = static fn (string $text): string => str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $text);
        $recipients = '';
        foreach ($spec['to'] as $key => $value) {
            $recipients .= (is_int($key) === true ? $value . "\t" : $key . "\t" . $value) . "\n";
        }
        $files = [
            'kind.txt' => 'message',
            'wire-file.txt' => $bodyFile,
            'date.txt' => (string) $spec['date'],
            'from-address.txt' => $spec['fromAddress'],
            'from-name.txt' => $spec['fromName'],
            'to.txt' => $recipients,
            'subject.txt' => $spec['subject'],
            'reply-to.txt' => $spec['replyTo'] ?? '',
            'message-id.txt' => $message->messageId(),
            'text.bin' => $crlf($spec['text']),
            'html.bin' => $crlf($spec['html']),
            'attachments.txt' => (string) count($spec['attachments'] ?? []),
        ];
        foreach (array_values($spec['attachments'] ?? []) as $number => $attachment) {
            $files['attachment-' . $number . '.name'] = $attachment['expectName'] ?? $attachment['name'];
            $files['attachment-' . $number . '.type'] = $attachment['type'] ?? 'application/octet-stream';
            $files['attachment-' . $number . '.bin'] = $attachment['content'];
        }
        foreach ($files as $name => $content) {
            file_put_contents($folder . '/' . $name, $content);
        }
    }

    public function finish(): int
    {
        $failed = $this->ran - $this->passed;
        echo "\nthe proofs reached their end: {$this->ran} ran, {$this->passed} passed, {$failed} failed\n";
        return $failed === 0 ? 0 : 1;
    }
}

/**
 * A value shown every way PHP can show one: printed, dumped, exported, cast,
 * turned into JSON and saved. Whatever each way reveals goes into the haystack.
 */
function dumpEveryWay(mixed $value): string
{
    // Dumping an open connection makes PHP grumble about the socket inside it.
    // Those grumbles are part of what gets searched, not a failure of a proof.
    $grumbles = '';
    set_error_handler(static function (int $level, string $message) use (&$grumbles): bool {
        $grumbles .= $message;
        return true;
    });
    ob_start();
    print_r($value);
    var_dump($value);
    var_export($value);
    print_r((array) $value);
    echo (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
    try {
        echo serialize($value);
    } catch (Throwable $fault) {
        echo $fault->getMessage() . $fault->getTraceAsString();
    }
    $shown = (string) ob_get_clean();
    restore_error_handler();
    return $shown . $grumbles;
}

/**
 * A small, ordinary message.
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function plainSpec(int $now, array $over = []): array
{
    return array_merge([
        'date' => $now,
        'fromAddress' => 'office@standin.test',
        'fromName' => 'The Office',
        'to' => ['reader@standin.test'],
        'subject' => 'A short test message',
        'text' => "Hello,\n\nThis is a test.\n",
        'html' => '<p>Hello,</p><p>This is a test.</p>',
        'replyTo' => '',
        'attachments' => [],
    ], $over);
}

/**
 * A message with everything that can go wrong in it: Welsh, Polish and Chinese
 * letters and an emoji in the names and the subject, a name with a comma and
 * quote marks, lines beginning with full stops, and three attachments, one
 * holding every possible byte and one with a long Chinese file name.
 *
 * @return array<string,mixed>
 */
function richSpec(int $now): array
{
    $everyByte = '';
    for ($byte = 0; $byte < 256; $byte++) {
        $everyByte .= chr($byte);
    }
    return [
        'date' => $now,
        'fromAddress' => 'swyddfa@standin.test',
        'fromName' => 'Gŵyl Ddewi — Swyddfa’r Eglwys',
        'to' => [
            'sian@standin.test' => 'Siân Ŵyn-Jones',
            'lukasz@standin.test' => 'Łukasz Żółć',
            'wang@standin.test' => '王小明 😀',
            'plain@standin.test',
            'mary@standin.test' => 'Jones, Mary "MJ"',
            'simple@standin.test' => 'Simple Name',
            // 🔤 Two names that a careless encoder is CERTAIN to cut in the middle
            // of a letter: a long run of two-byte letters, once from the very
            // start and once after a single plain letter. Whatever length the
            // encoder cuts at, odd or even, one of the two has a letter across
            // the cut. (The names above did not: by chance every cut in them fell
            // between letters, so the check on the wire had nothing to catch.)
            'even@standin.test' => str_repeat('Żółćęśąźń', 4),
            'odd@standin.test' => 'A' . str_repeat('Żółćęśąźń', 4),
        ],
        'subject' => 'Cyfarfod nos Fercher: Zażółć gęślą jaźń — 你好，世界 😀 — and a long tail so that this subject has to be folded over several lines',
        'text' => "Annwyl bawb,\n\n.This line begins with a full stop\n..and this one with two\n.\nZażółć gęślą jaźń. 你好，世界 😀\r\n"
            . str_repeat('A long line without a break. ', 30) . "\n\nCofion,\nY Swyddfa\n",
        'html' => "<p>Annwyl bawb,</p>\n<p>Zażółć gęślą jaźń. 你好，世界 😀</p>\n<p>." . str_repeat('A long line without a break. ', 30) . '</p>',
        'replyTo' => 'atebion@standin.test',
        'attachments' => [
            ['name' => 'every-byte.bin', 'type' => 'application/octet-stream', 'content' => str_repeat($everyByte, 30) . "\r\n.\r\n" . $everyByte],
            [
                'name' => '/tmp/some/folder/报告 2026 – Gŵyl Ddewi (terfynol) – fersiwn hir iawn o enw ffeil.pdf',
                'expectName' => '报告 2026 – Gŵyl Ddewi (terfynol) – fersiwn hir iawn o enw ffeil.pdf',
                'type' => 'application/pdf',
                'content' => "%PDF-1.7\n" . random_bytes(6000),
            ],
            ['name' => 'notes "final" v2.txt', 'type' => 'text/plain', 'content' => "Line one\nLine two\n.\n"],
        ],
    ];
}

/** @param array<string,mixed> $spec */
function build(array $spec): Message
{
    return new Message(
        $spec['date'],
        $spec['fromAddress'],
        $spec['fromName'],
        $spec['to'],
        $spec['subject'],
        $spec['text'],
        $spec['html'],
        $spec['replyTo'] ?? '',
        array_map(static fn (array $a): array => ['name' => $a['name'], 'type' => $a['type'] ?? '', 'content' => $a['content']], $spec['attachments'] ?? []),
        $spec['messageId'] ?? '',
    );
}

/** A port on 127.0.0.1 with nothing listening on it: taken for a moment, then given back. */
function closedPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);
    return $port;
}

/** @param array<string,string> $options */
function runCases(array $options): int
{
    $t = new Bench($options);
    $now = $t->now;

    // Any warning PHP raises that the library did not catch itself is a fault
    // in the library (it promises none reach the application), so it fails
    // the proof under way.
    set_error_handler(static function (int $level, string $message, string $file, int $line) use ($t): bool {
        $t->haystack .= $message;
        $t->check(false, 'PHP raised: ' . $message . ' (' . basename($file) . ':' . $line . ')');
        return true;
    });

    if (isset($options['fresh-process']) === true) {
        // X9 comes FIRST: it is only a proof while no Secret has been made in this process.
        $t->proof('X9', 'A Secret made without its constructor is simply an empty Secret, even before any other Secret exists', static function () use ($t): void {
            $table = new ReflectionProperty(Secret::class, 'vault');
            $t->check($table->getValue() === null, 'no Secret has been made in this process yet (if this fails, something above this proof made one, and the proof no longer tests what it says)');
            // PHP lets an object be made without running its constructor. The
            // table of passwords used to be created only in the constructor, so
            // on such an object wipe() stopped with a TypeError.
            $bare = (new ReflectionClass(Secret::class))->newInstanceWithoutConstructor();
            $t->check($bare->reveal() === '' && $bare->isEmpty() === true, 'it holds no password');
            $bare->wipe();
            $t->check($bare->reveal() === '', 'wipe() does nothing harmful to it');
            $t->check($bare->scrub('250 ordinary words') === '250 ordinary words' && (string) $bare === Secret::HIDDEN, 'and it removes nothing from text and prints as the placeholder');
            // An ordinary Secret made afterwards is unaffected.
            $real = new Secret(TEST_PASS);
            $t->check($real->reveal() === TEST_PASS && $bare->reveal() === '', 'a real Secret made next keeps its password, and the bare one stays empty');
            $real->wipe();
            $t->check($real->reveal() === '', 'and wipe() empties the real one');
        });
        $t->proof('X8', 'On a PHP that cannot convert a non-English domain, such an address is refused, not guessed, and nothing else changes', static function () use ($t, $now): void {
            $t->check(function_exists('idn_to_ascii') !== true, 'idn_to_ascii() is available here: start this through the organiser, which switches it off');
            foreach (['to' => ['to' => ['post@bücher.test']], 'fromAddress' => ['fromAddress' => 'swyddfa@bücher.test'], 'replyTo' => ['replyTo' => 'atebion@例え.test']] as $field => $over) {
                try {
                    build(plainSpec($now, $over));
                    $t->check(false, 'a non-English domain in "' . $field . '" was NOT refused');
                } catch (MessageRefused $refusal) {
                    $t->check($refusal->field === $field, 'refused under the name "' . $field . '", got "' . $refusal->field . '"');
                    $t->check(strpos($refusal->getMessage(), 'cannot convert') !== false, 'the refusal says the domain cannot be converted here: ' . $refusal->getMessage());
                }
            }
            // Everything that needs no conversion still works: plain addresses, and a domain already in its ASCII form.
            $message = build(plainSpec($now, ['to' => ['reader@standin.test', 'post@xn--bcher-kva.test' => 'Buchhandlung Müller']]));
            $t->check($message->recipients() === ['reader@standin.test', 'post@xn--bcher-kva.test'], 'plain addresses, and a domain already in ASCII form, are accepted as they are');
            $t->check(strpos($message->bytes(), 'post@xn--bcher-kva.test') !== false && $message->size() > 300, 'and the message is built');
        });
        return $t->finish();
    }

    if (isset($options['small-memory']) === true) {
        $t->proof('X11', 'With 32 MB of memory, a 10 MB attachment is refused in words before it is read, and nothing ends in a fatal error', static function () use ($t, $now): void {
            $t->check(ini_get('memory_limit') === '32M', 'this PHP is limited to 32 MB (it says ' . ini_get('memory_limit') . '): start this through the organiser');
            // A 10 MB file, written a megabyte at a time so that this proof never holds it.
            $path = $t->dir . '/ten-megabytes.bin';
            $file = fopen($path, 'wb');
            for ($i = 0; $i < 10; $i++) {
                fwrite($file, str_repeat(chr(65 + $i), 1048576));
            }
            fclose($file);
            $before = memory_get_peak_usage(true);
            try {
                Message::fileAttachment($path);
                $t->check(false, 'the 10 MB file was read, not refused');
            } catch (MessageRefused $refusal) {
                $t->check($refusal->field === 'attachment' && strpos($refusal->getMessage(), 'not enough memory') !== false, 'the refusal says there is not enough memory: ' . $refusal->getMessage());
                $t->check(strpos($refusal->getMessage(), 'AAAA') === false && strpos($refusal->getMessage(), $t->dir) === false, 'and repeats neither the content nor the path');
                $t->say('refused with: ' . $refusal->getMessage());
            }
            $t->check(memory_get_peak_usage(true) - $before < 2097152, 'the file was NOT read first: memory in use did not grow (it grew by ' . (memory_get_peak_usage(true) - $before) . ' bytes)');
            // The same content handed over directly, as an application might.
            $content = str_repeat('B', Message::MAX_ATTACHMENT_BYTES);
            try {
                build(plainSpec($now, ['attachments' => [['name' => 'big.bin', 'content' => $content]]]));
                $t->check(false, 'a message with 10 MB of content was accepted in 32 MB');
            } catch (MessageRefused $refusal) {
                $t->check(strpos($refusal->getMessage(), 'not enough memory') !== false, 'content handed over directly is refused the same way');
            }
            unset($content);
            // What does fit still goes: 1 MB needs about 6.
            $small = build(plainSpec($now, ['attachments' => [['name' => 'small.bin', 'content' => str_repeat('C', 1048576)]]]));
            $t->check($small->size() > 1048576, 'a 1 MB attachment is built as usual');
            // 🧮 Memory that runs short AFTER a message was made: the send
            // refuses in words too, and makes no connection.
            $ballast = str_repeat('D', max(0, 32 * 1048576 - memory_get_usage(true) - 2 * 1048576));
            [$result] = $t->send($t->transport('small-memory'), $small);
            unset($ballast);
            $t->expect($result, SendResult::REFUSED, SendResult::STAGE_MESSAGE, SendResult::REASON_TOO_BIG);
            $t->check(strpos($result->serverWords, 'not enough memory') !== false, 'the result says why: ' . $result->serverWords);
            $t->check($t->count('small-memory', 'connect', '', false) === 0, 'and no connection was made');
            unlink($path);
        });
        return $t->finish();
    }

    if (isset($options['low-floor']) === true) {
        // WHY THIS PROOF HAS A PHP OF ITS OWN. OpenSSL 3 will not speak TLS 1.1
        // whatever it is asked, so against a TLS 1.1 server the library's own
        // rule is never the one that says no, and a proof run that way passes
        // with the rule taken out. (That is what the first version did, backed
        // by a reading of a setting PHP turned out not to obey.) Here OpenSSL
        // has been told it MAY speak TLS 1.1, for this process only. The
        // first step shows that really is so; after it, only the library's
        // rule stands between it and a TLS 1.1 connection.
        $t->proof('L17e', 'With OpenSSL itself willing to speak TLS 1.1, the library still refuses a TLS 1.1 server, before any handshake completes', static function () use ($t, $now): void {
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $plain = stream_socket_client('tcp://127.0.0.1:' . $t->ports['old-tls-control'], $errorNumber, $errorText, 10.0, STREAM_CLIENT_CONNECT, $context);
            $agreed = '';
            if (is_resource($plain) === true) {
                set_error_handler(static fn (): bool => true);
                $on = stream_socket_enable_crypto($plain, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                restore_error_handler();
                $agreed = $on === true ? (string) (stream_get_meta_data($plain)['crypto']['protocol'] ?? '') : '';
                fclose($plain);
            }
            $t->check($agreed === 'TLSv1.1', 'THE CONTROL: PHP asked for "any TLS" does connect to this server with TLS 1.1 (it agreed "' . $agreed . '"). If this fails, OpenSSL here could not be made to speak TLS 1.1 and the rest of this proof shows nothing');
            $t->say('the control connected with "' . $agreed . '", so OpenSSL here was willing; the library was then tried against the same kind of server');

            [$result] = $t->send($t->transport('old-tls-low'), build(plainSpec($now)));
            $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_NO_ENCRYPTION, null, 'checked:');
            [$result] = $t->send($t->transport('old-tls-low-pinned', ['security' => SmtpConfig::TLS]), build(plainSpec($now)), SendOptions::onlyThisCertificate(str_repeat('ab', 32)));
            $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_NO_ENCRYPTION, null, 'with a certificate approved:');
            $reader = new CertificateReader($t->config('old-tls-low-reader', ['security' => SmtpConfig::TLS]), new SystemClock());
            $t->check($reader->read() === null, 'the connection that only reads a certificate refuses it too');
            foreach (['old-tls-low', 'old-tls-low-pinned', 'old-tls-low-reader'] as $scenario) {
                // The server's own account. Had the library asked for "any
                // TLS" and refused only afterwards, the server would have
                // completed a TLS 1.1 handshake and written "ok" here.
                $t->check($t->count($scenario, 'tls', 'ok') === 0, $scenario . ': the server never completed a handshake: ' . implode(' | ', $t->story($scenario)));
                $t->check($t->count($scenario, 'tls', 'failed') >= 1, $scenario . ': and it did try one');
                $t->check($t->count($scenario, 'auth') === 0, $scenario . ': no sign-in was sent');
            }
        });
        return $t->finish();
    }

    $t->proof('X0', 'The shell functions a shared host may forbid are switched off for these proofs', static function () use ($t): void {
        foreach (explode(',', DISABLED_FOR_THE_LIBRARY) as $function) {
            $t->check(function_exists($function) !== true, $function . '() is still available: start this test through the organiser');
        }
        $t->check(ini_get('zend.exception_ignore_args') !== '1', 'stack traces carry no arguments here, so the password search of traces would see nothing');
    });

    // -------------------------------------------------------------------------
    // L1 – L3: a message arrives whole and well formed
    // -------------------------------------------------------------------------

    foreach (['L1a' => ['accept-starttls', SmtpConfig::STARTTLS, 'STARTTLS'], 'L1b' => ['accept-implicit', SmtpConfig::TLS, 'encryption from the first byte']] as $id => [$scenario, $security, $label]) {
        $t->proof($id, 'A server that accepts, by ' . $label . ': the whole message arrives', static function () use ($t, $now, $id, $scenario, $security): void {
            $spec = richSpec($now);
            $message = build($spec);
            $transport = $t->transport($scenario, ['security' => $security]);
            [$result] = $t->send($transport, $message);
            $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250);
            $t->check(strpos($result->serverWords, 'queued as STANDIN') !== false, "the server's own closing words are kept: " . $result->serverWords);
            $transport->close();

            $expected = $security === SmtpConfig::STARTTLS
                ? ['0 line EHLO client.test', '0 line STARTTLS', '1 tls ok', '1 line EHLO client.test']
                : ['1 tls ok', '1 line EHLO client.test'];
            $expected[] = '1 auth PLAIN user=' . TEST_USER . ' matched=1';
            // 📮 The envelope sender is the From address, so bounces return to it.
            $expected[] = '1 line MAIL FROM:<swyddfa@standin.test>';
            foreach ($message->recipients() as $address) {
                $expected[] = '1 line RCPT TO:<' . $address . '>';
            }
            array_push($expected, '1 line DATA', '1 body complete=1', '1 line QUIT');
            $story = $t->story($scenario);
            $t->check($story === $expected, "the server saw exactly the expected conversation; it saw:\n          " . implode("\n          ", $story));
            $t->check($message->attachmentCount() === 3, 'the message carries its three attachments');
            $t->writeExpectation($id, $spec, $message, $t->lastBodyFile($scenario));

            // The step-by-step story for a "send a test" page.
            $steps = implode("\n", $result->transcript);
            $t->check(strpos($steps, 'Encryption is on (TLSv1.') !== false && strpos($steps, 'issued by') !== false, 'the transcript says encryption is on and whose certificate it is');
            $t->check(strpos($steps, 'C: AUTH PLAIN [removed]') !== false, 'the transcript shows the sign-in line replaced');
            $t->check(strpos($steps, 'C: [the message, ' . $message->size() . ' bytes]') !== false, 'the transcript shows the message as a size, not its content');
            $t->check(strpos($steps, 'Annwyl') === false && strpos($steps, base64_encode('Annwyl')) === false, 'the transcript holds none of the message body');
        });
    }

    $t->proof('L2', 'Every line ends CR LF; no header line passes 78 characters and no body line 76', static function () use ($t, $now): void {
        $many = [];
        for ($i = 0; $i < Message::MAX_RECIPIENTS; $i++) {
            $many['person' . $i . '@standin.test'] = $i % 3 === 0 ? 'Żaneta Wiśniewska-Kowalczyk ' . $i : ($i % 3 === 1 ? 'Person, Number ' . $i : '');
        }
        $specs = [
            'the rich message' => richSpec($now),
            'a hundred recipients' => plainSpec($now, ['to' => $many, 'subject' => str_repeat('long plain subject ', 20)]),
            'a subject of one long word' => plainSpec($now, ['subject' => str_repeat('x', 300), 'fromName' => str_repeat('Ł', 200)]),
            // The same run of two-byte letters starting one byte later, so that an
            // encoder cutting at an EVEN length is caught as surely as an odd one.
            'two-byte letters after one plain letter' => plainSpec($now, ['subject' => 'A' . str_repeat('Ł', 200)]),
            'a subject that looks like an encoded word' => plainSpec($now, ['subject' => '=?UTF-8?B?bm90IHJlYWxseQ==?=']),
            'a very long file name' => plainSpec($now, ['attachments' => [['name' => str_repeat('报告', 40) . '.pdf', 'content' => 'x'], ['name' => str_repeat('a', 200) . '.txt', 'content' => 'y']]]),
        ];
        $encodedWords = 0;
        foreach ($specs as $label => $spec) {
            $bytes = build($spec)->bytes();
            // 🔤 Each "encoded word" must hold whole characters BY ITSELF. A mail
            // program may decode each word separately, so a letter of several
            // bytes cut across two words shows as two broken characters. (Reading
            // the message back through Python cannot show this: its parser joins
            // the halves again. Found by planting exactly that fault.)
            preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]*)\?=/', $bytes, $words, PREG_SET_ORDER);
            foreach ($words as [$word, $payload]) {
                $encodedWords++;
                $decoded = base64_decode($payload, true);
                $t->check(is_string($decoded) === true && preg_match('//u', $decoded) === 1, $label . ': an encoded word does not hold whole characters by itself: ' . $word);
                $t->check(strlen($word) <= 75, $label . ': an encoded word is ' . strlen($word) . ' characters long (the limit is 75)');
            }
            $t->check(preg_match('/[^\x00-\x7F]/', $bytes) !== 1, $label . ': the message is plain ASCII on the wire');
            $t->check(preg_match('/(?<!\r)\n|\r(?!\n)/', $bytes) !== 1, $label . ': no line ends in a bare CR or a bare LF');
            [$head, $body] = explode("\r\n\r\n", $bytes, 2);
            foreach (explode("\r\n", $head) as $line) {
                $t->check(strlen($line) <= 78, $label . ': a header line is ' . strlen($line) . ' characters: ' . substr($line, 0, 60));
            }
            $inPartHeaders = false;
            foreach (explode("\r\n", $body) as $line) {
                // Lines inside a part's own small header may be up to 78; the content lines up to 76.
                $inPartHeaders = strncmp($line, '--', 2) === 0 ? true : ($line === '' ? false : $inPartHeaders);
                $t->check(strlen($line) <= ($inPartHeaders === true ? 78 : 76), $label . ': a body line is ' . strlen($line) . ' characters');
            }
        }
        // 87 when this was written. The floor is there so that "found none" can never pass.
        $t->check($encodedWords >= 50, 'the shapes above did produce encoded words to look at (' . $encodedWords . ' of them)');
        $t->say('looked at ' . $encodedWords . ' encoded words, each decoded by itself');
        // The one stated exception: an address with no place to fold sits on a longer line of its own.
        $longAddress = str_repeat('a', 60) . '@' . str_repeat('b', 50) . '.standin.test';
        $head = explode("\r\n\r\n", build(plainSpec($now, ['to' => [$longAddress => 'Long Address']]))->bytes(), 2)[0];
        $longest = max(array_map('strlen', explode("\r\n", $head)));
        $t->check($longest > 78 && $longest < 998, 'an address too long to fold is on a line of its own under the hard limit of 998 (it is ' . $longest . ')');
    });

    $t->proof('L3', 'Date, Message-ID, From, To and MIME-Version are present; Reply-To only when set; the same bytes on a retry', static function () use ($t, $now): void {
        $spec = plainSpec($now, ['replyTo' => 'replies@standin.test']);
        $message = build($spec);
        $head = explode("\r\n\r\n", $message->bytes(), 2)[0];
        $t->check(strpos($head, 'Date: ' . gmdate('D, d M Y H:i:s', $now) . ' +0000' . "\r\n") === 0, 'the Date line is the given moment, written in UTC with +0000');
        foreach (['From: The Office <office@standin.test>', 'To: reader@standin.test', 'Reply-To: replies@standin.test', 'Subject: A short test message', 'MIME-Version: 1.0', 'Message-ID: ' . $message->messageId()] as $line) {
            $t->check(strpos("\r\n" . $head . "\r\n", "\r\n" . $line . "\r\n") !== false, 'the headers hold the line: ' . $line);
        }
        $t->check(preg_match('/\A<[0-9a-f]{32}@standin\.test>\z/', $message->messageId()) === 1, "the identifier ends in the sender's own domain: " . $message->messageId());
        $t->check($message->bytes() === $message->bytes(), 'building the message twice gives the same bytes');
        $t->check(preg_match('/^(X-Mailer|User-Agent|X-Powered-By):/mi', $head) !== 1, 'no header names the software');
        $t->check(strpos(build(plainSpec($now))->bytes(), 'Reply-To:') === false, 'no Reply-To line when none was asked for');
        $again = build($spec + ['messageId' => $message->messageId()]);
        $t->check($again->messageId() === $message->messageId(), 'a stored identifier is kept when the message is built again for a retry');
        $t->check(build(plainSpec($now))->messageId() !== $message->messageId(), 'two different messages get different identifiers');
        $t->check($message->sender() === 'office@standin.test' && $message->recipients() === ['reader@standin.test'], 'the envelope is the From address and the recipients');
        // A non-English domain is converted, where this PHP can.
        if (function_exists('idn_to_ascii') === true) {
            $t->check(build(plainSpec($now, ['to' => ['post@bücher.test']]))->recipients() === ['post@xn--bcher-kva.test'], 'a non-English domain is converted to its ASCII form');
            $t->say('this PHP can convert a non-English domain, and the conversion was checked');
        } else {
            // Not a failure: this PHP has no "intl" extension. But it is said out
            // loud, so that nobody reads this run as having tested the conversion.
            $t->say('NOT TESTED HERE: this PHP has no idn_to_ascii(), so converting a non-English domain could not be checked (proof X8 checks that it is refused)');
        }

        // On the wire: sent through a server, the envelope sender equals From.
        [$result] = $t->send($t->transport('headers'), $message);
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250);
        $t->check($t->count('headers', 'line', 'MAIL FROM:<office@standin.test>', false) === 1, 'the server was given the From address as the envelope sender');
    });

    $t->proof('L3b', 'The Date line names the same moment whatever time zone PHP is set to, across both clock-change nights', static function () use ($t, $now): void {
        // The owner's standing rule on times: prove them in zones that are
        // not UTC, on both nights the clocks change, in zones that do change.
        // (This test used to run only in whatever zone the machine had. A
        // Date written with the local clock but still marked "+0000" passed
        // it whole.) The nights are found from each zone's own rules, counted
        // from today, so this cannot go stale.
        $window = 400 * 86400; // only how far to LOOK for a change; nothing counts days with it
        $zones = ['Europe/London', 'America/New_York', 'Pacific/Chatham', 'Australia/Lord_Howe', 'UTC'];
        $before = date_default_timezone_get();
        $tried = 0;
        $awayFromUtc = 0;
        try {
            foreach ($zones as $zone) {
                $changes = array_slice((new DateTimeZone($zone))->getTransitions($now - $window, $now + $window), 1);
                $moments = [$now];
                $directions = [];
                foreach ($changes as $change) {
                    $directions[$change['isdst'] === true ? 'forward' : 'back'] = true;
                    array_push($moments, $change['ts'] - 3600, $change['ts'] - 1, $change['ts'], $change['ts'] + 1, $change['ts'] + 1800, $change['ts'] + 3600);
                }
                if ($zone !== 'UTC') {
                    $t->check(count($directions) === 2, $zone . ': both a change forward and a change back fall in the two years looked at (found ' . implode(', ', array_keys($directions)) . ')');
                }
                date_default_timezone_set($zone);
                $away = 0;
                foreach ($moments as $moment) {
                    $tried++;
                    $away += (int) date('Z', $moment) !== 0 ? 1 : 0;
                    $head = explode("\r\n\r\n", build(plainSpec($now, ['date' => $moment]))->bytes(), 2)[0];
                    $line = preg_match('/^Date: (.*)$/m', str_replace("\r", '', $head), $found) === 1 ? $found[1] : '';
                    // What it should be, worked out with no help from the zone PHP is set to.
                    $expected = (new DateTimeImmutable('@' . $moment))->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' +0000';
                    $t->check($line === $expected, $zone . ': the Date line for moment ' . $moment . ' is "' . $line . '", expected "' . $expected . '"');
                    // And read back as a moment, the way a mail program reads it.
                    $read = DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $line);
                    $t->check($read !== false && $read->getTimestamp() === $moment, $zone . ': read back, the Date line is the moment it was given (' . $moment . ')');
                }
                if ($zone !== 'UTC') {
                    $t->check($away > 0, $zone . ': at some of the moments tried this zone was not on UTC, so a Date in local time would show');
                }
                $awayFromUtc += $away;
            }
        } finally {
            date_default_timezone_set($before);
        }
        $t->say($tried . ' moments in ' . count($zones) . ' time zones; at ' . $awayFromUtc . ' of them the zone was away from UTC');
    });

    // -------------------------------------------------------------------------
    // L4: a smuggled line break refuses the message
    // -------------------------------------------------------------------------

    $t->proof('L4a', 'A line break or control character smuggled into any header field refuses the whole message', static function () use ($t, $now): void {
        $smuggled = [
            'a CR LF and a new header' => "\r\nBcc: thief@evil.test",
            'a bare LF' => "\nBcc: thief@evil.test",
            'a bare CR' => "\rBcc: thief@evil.test",
            'a zero byte' => "\0",
            'a tab' => "\t",
            'a vertical tab' => "\x0B",
            'a delete character' => "\x7F",
            'a trailing line break' => "\n",
        ];
        $unicode = ['a Unicode line separator' => "\u{2028}", 'a Unicode next-line character' => "\u{0085}"];
        // field => how to put a payload into it
        $fields = [
            'subject' => static fn (string $x): array => ['subject' => 'Hello' . $x],
            'fromName' => static fn (string $x): array => ['fromName' => 'Office' . $x],
            'fromAddress' => static fn (string $x): array => ['fromAddress' => 'office@standin.test' . $x],
            'to' => static fn (string $x): array => ['to' => ['reader@standin.test' . $x]],
            'toName' => static fn (string $x): array => ['to' => ['reader@standin.test' => 'Reader' . $x]],
            'replyTo' => static fn (string $x): array => ['replyTo' => 'replies@standin.test' . $x],
            'attachmentName' => static fn (string $x): array => ['attachments' => [['name' => 'file' . $x . '.txt', 'content' => 'x']]],
            'attachmentType' => static fn (string $x): array => ['attachments' => [['name' => 'file.txt', 'type' => 'text/plain' . $x, 'content' => 'x']]],
            'messageId' => static fn (string $x): array => ['messageId' => '<abc' . $x . '@standin.test>'],
        ];
        $tried = 0;
        foreach ($fields as $field => $put) {
            $payloads = in_array($field, ['subject', 'fromName', 'toName', 'attachmentName'], true) === true ? $smuggled + $unicode : $smuggled;
            foreach ($payloads as $label => $payload) {
                $tried++;
                try {
                    build(plainSpec($now, $put($payload)));
                    $t->check(false, $field . ' with ' . $label . ' was NOT refused');
                } catch (MessageRefused $refusal) {
                    $t->check($refusal->field === $field, $field . ' with ' . $label . ': the refusal names the field "' . $refusal->field . '"');
                    $t->check(strpos($refusal->getMessage(), 'thief') === false && preg_match('/[\x00-\x1F\x7F]/', $refusal->getMessage()) !== 1, $field . ': the refusal does not repeat what was smuggled');
                }
            }
        }
        $t->say("tried $tried combinations of field and character");
        // Addresses that would end the envelope command early, or are not addresses.
        foreach (['a@b.test> SIZE=1', 'a b@standin.test', '"quoted name"@standin.test', '<a@standin.test>', 'a@standin.test,b@standin.test', 'no-at-sign', '@standin.test', 'a@', 'a@nodot', 'ünï@standin.test', 'a@-bad-.test'] as $bad) {
            try {
                build(plainSpec($now, ['to' => [$bad]]));
                $t->check(false, 'the address ' . $bad . ' was NOT refused');
            } catch (MessageRefused $refusal) {
                $t->check($refusal->field === 'to', 'a bad address is refused as the field "to"');
            }
        }
        // Other things that are refused, not repaired.
        $others = [
            ['text', ['text' => "  \n "]], ['html', ['html' => '']], ['to', ['to' => []]], ['date', ['date' => 0]],
            ['attachment', ['attachments' => [['name' => 'empty.txt', 'content' => '']]]],
            ['subject', ['subject' => "broken \xC3\x28 utf-8"]],
            // A recipient that is not text at all. The constructor's own check is
            // what refuses these: PHP does not enforce what a comment says an
            // array holds. (A static analyser calls that check "redundant"
            // because of the comment. It is not, and this is the proof.)
            ['to', ['to' => ['reader@standin.test' => 123]]],
            ['to', ['to' => [null]]],
            ['to', ['to' => [['nested@standin.test']]]],
        ];
        foreach ($others as [$field, $over]) {
            try {
                build(plainSpec($now, $over));
                $t->check(false, 'a message with a bad ' . $field . ' was NOT refused');
            } catch (MessageRefused $refusal) {
                $t->check($refusal->field === $field, 'a bad ' . $field . ' is refused under that name, got "' . $refusal->field . '"');
            }
        }
    });

    $t->proof('L4b', 'A command holding a line break is never written to the server, whoever asks', static function () use ($t): void {
        $connection = new Connection($t->config('unencrypted-guard'), new SystemClock());
        $deadline = (new SystemClock())->ticks() + 20;
        $t->check($connection->open($deadline) === '', 'connected to the stand-in');
        $t->check($connection->readReply($deadline)['code'] === 220, 'the stand-in greeted');
        foreach (["MAIL FROM:<a@standin.test>\r\nRCPT TO:<thief@evil.test>", "RSET\nMAIL FROM:<x@standin.test>", "NOOP\0", "NOOP\r"] as $line) {
            $reply = $connection->command($line, $deadline);
            $t->check($reply['problem'] === Connection::NOT_ALLOWED, 'the command was refused before anything was written');
        }
        // 🔒 And a line that carries a password is refused while encryption is off.
        $reply = $connection->secretCommand('AUTH PLAIN ' . base64_encode("\0" . TEST_USER . "\0" . TEST_PASS), $deadline, 'AUTH PLAIN [removed]');
        $t->check($reply['problem'] === Connection::NOT_ALLOWED, 'a sign-in line is refused on a connection that is not encrypted');
        $t->check($connection->command('NOOP', $deadline)['code'] === 500, 'the connection still works for an ordinary command');
        $connection->close(true);
        $t->check($t->story('unencrypted-guard') === ['0 line NOOP', '0 line QUIT'], 'the server saw only the two honest commands: ' . implode(' | ', $t->story('unencrypted-guard')));
    });

    // -------------------------------------------------------------------------
    // L5: signing in
    // -------------------------------------------------------------------------

    $t->proof('L5a', 'A refused sign-in is reported in the server\'s words, and no message command follows', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('refuse-signin', ['password' => WRONG_PASS]), build(plainSpec($now)));
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_AUTH, SendResult::REASON_SERVER_SAID_NO, 535);
        $t->check(strpos($result->serverWords, 'the user name or password is wrong') !== false, "the server's own words are kept: " . $result->serverWords);
        $t->check($result->isAboutTheMessage() !== true, 'the refusal is marked as being about the route, not the message');
        $t->check($t->count('refuse-signin', 'auth', 'PLAIN user=' . TEST_USER . ' matched=0') === 1, 'the server saw one failed sign-in');
        $t->check($t->count('refuse-signin', 'line', 'MAIL') === 0 && $t->count('refuse-signin', 'body') === 0, 'no MAIL command and no message followed');
    });

    $t->proof('L5b', 'A server offering only LOGIN is signed in to; one offering no known method gets no password', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('login-only'), build(plainSpec($now)));
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250);
        $t->check($t->count('login-only', 'auth', 'LOGIN user=' . TEST_USER . ' matched=1', false) === 1, 'the server saw a LOGIN sign-in with the right password');
        $steps = implode("\n", $result->transcript);
        $t->check(substr_count($steps, 'C: [removed]') === 2, 'the transcript shows both LOGIN lines replaced');

        [$result] = $t->send($t->transport('no-signin-method'), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_AUTH, SendResult::REASON_NO_SIGN_IN_METHOD);
        $t->check(strpos($result->serverWords, 'XOAUTH2') !== false, 'the result says what the server does offer');
        $t->check($t->count('no-signin-method', 'auth') === 0, 'no sign-in line was sent');
    });

    // -------------------------------------------------------------------------
    // L6, L15: certificates that fail, and why
    // -------------------------------------------------------------------------

    $certificates = [
        'cert-expired' => [SmtpConfig::STARTTLS, CertificateFacts::EXPIRED, 'expired on'],
        'cert-expired-implicit' => [SmtpConfig::TLS, CertificateFacts::EXPIRED, 'expired on'],
        'cert-notyet' => [SmtpConfig::STARTTLS, CertificateFacts::NOT_YET_VALID, 'not valid until'],
        'cert-wrongname' => [SmtpConfig::STARTTLS, CertificateFacts::WRONG_NAME, 'another name (other.test, *.wild.test)'],
        'cert-selfsigned' => [SmtpConfig::STARTTLS, CertificateFacts::SELF_SIGNED, 'signed by itself'],
        'cert-privateca' => [SmtpConfig::STARTTLS, CertificateFacts::UNKNOWN_ISSUER, 'cannot be traced to an authority'],
    ];
    foreach ($certificates as $scenario => [$security, $reason, $words]) {
        $t->proof('L6-' . substr($scenario, 5, 9), 'A certificate that is ' . $reason . ' (' . $scenario . '): nothing is sent, and the result says why', static function () use ($t, $now, $scenario, $security, $reason, $words): void {
            [$result] = $t->send($t->transport($scenario, ['security' => $security]), build(plainSpec($now)));
            $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE);
            $facts = $result->certificate;
            $t->check($facts !== null && $facts->reasons === [$reason], 'the one reason given is "' . $reason . '": ' . implode(', ', $facts->reasons ?? ['no facts']));
            $t->check(strpos($result->serverWords, $words) !== false, 'the plain words say so: ' . $result->serverWords);
            $t->check($facts !== null && SendOptions::isFingerprint($facts->sha256) === true && $facts->issuer !== '' && $facts->validTo > 0, 'the facts carry the fingerprint, the issuer and the dates');
            // What the server saw: a first connection whose encryption failed,
            // a second that encrypted and then said nothing at all.
            $story = $t->story($scenario);
            $t->check($t->count($scenario, 'auth') === 0, 'no sign-in line reached the server');
            $t->check(preg_grep('/^1 line /', $story) === [], 'nothing was said after encryption on either connection: ' . implode(' | ', $story));
            // (A wrong NAME is found by PHP after the handshake itself has
            // finished, so that server sees two completed handshakes; for the
            // others it sees one refused and one completed.)
            $t->check($t->count($scenario, 'tls', 'ok') >= 1, 'the connection that read the certificate did encrypt');
            $t->check($t->count($scenario, 'connect') === 2, 'two connections: the checked one, and the one that read the certificate');
        });
    }

    $t->proof('L6-noauth', 'The same good certificate WITHOUT the test\'s authority handed in is refused', static function () use ($t, $now): void {
        // With the test's authority: accepted (proof L1). Without it, the
        // machine's own list does not know the issuer, so it must be refused.
        [$result] = $t->send($t->transport('cert-good-no-authority', ['caFile' => '']), build(plainSpec($now)));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE);
        $t->check($result->certificate !== null && $result->certificate->reasons === [CertificateFacts::UNKNOWN_ISSUER], 'the reason is an unknown issuer');
        $t->check($t->count('cert-good-no-authority', 'auth') === 0 && $t->count('cert-good-no-authority', 'line', 'MAIL') === 0, 'no sign-in and no message reached the server');
    });

    $t->proof('L6h', 'A handshake that fails for a reason that is NOT the certificate is "try later", never a certificate fault; and a real fault is still named', static function () use ($t, $now): void {
        // An application may hold ALL mail and alert an administrator on a
        // certificate fault. So a fault on the line, or somebody in the middle
        // who cuts the handshake and then lets the genuine certificate
        // through, must not be able to raise that alarm.
        foreach (['cut-first' => SmtpConfig::STARTTLS, 'cut-first-implicit' => SmtpConfig::TLS, 'alert-first' => SmtpConfig::STARTTLS] as $scenario => $security) {
            $transport = $t->transport($scenario, ['security' => $security]);
            [$result] = $t->send($transport, build(plainSpec($now)));
            $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_ENCRYPTION_FAILED, null, $scenario . ', the handshake cut:');
            $t->check($result->certificate === null, $scenario . ': no certificate is offered for approval');
            $t->check(preg_match('/not accepted|was refused|cannot be traced|approv/i', $result->serverWords) !== 1 && strpos($result->serverWords, 'PHP did not say the certificate was at fault') !== false, $scenario . ': the words say the certificate was not blamed: ' . $result->serverWords);
            $t->check($result->isConnectionFailure() === true, $scenario . ': it counts as a connection failure, which a second route may be tried for');
            $t->check($t->count($scenario, 'connect', '', false) === 1, $scenario . ': no second connection was made to read a certificate nobody doubted');
            // The very next try, to the same server showing its genuine certificate, goes through.
            [$result] = $t->send($transport, build(plainSpec($now)));
            $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, $scenario . ', the next try:');
        }
        // 🔁 THE OTHER WAY ROUND. A real certificate fault is not hidden by a
        // cut handshake: the cut is "try later", and the try after it names
        // the fault for a person. (Each kind of bad certificate is "needs a
        // person" in the L6 proofs above; if a future PHP words its refusal
        // in a way the library does not know, those are the proofs that fail.)
        $transport = $t->transport('cut-first-expired');
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_ENCRYPTION_FAILED, null, 'an expired certificate behind a cut handshake, first try:');
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE, null, 'the same, next try:');
        $t->check(($result->certificate->reasons ?? []) === [CertificateFacts::EXPIRED], 'and it is named as expired');
        $t->check($t->count('cut-first-expired', 'auth') === 0, 'no sign-in was sent at any point');
        // A certificate PHP refused, on a server that then will not be read a
        // second time: still the certificate, still for a person. "Try later"
        // here would let somebody hide a bad certificate by dropping every
        // second connection.
        [$result] = $t->send($t->transport('expired-unreadable'), build(plainSpec($now)));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE, null, 'refused, then unreadable:');
        $t->check($result->certificate === null && strpos($result->serverWords, 'could not be read a second time') !== false, 'the result says it could not be read again, and offers nothing to approve: ' . $result->serverWords);
        $t->check($t->count('expired-unreadable', 'auth') === 0, 'no sign-in was sent');
    });

    $t->proof('L6n', "The library's own name rule decides as well as PHP's: the same answer on every PHP version", static function () use ($t, $now): void {
        // [scenario, the server name in the settings, accepted?]. Every
        // certificate here is in date and signed by the trusted authority.
        // PHP by itself accepts the first two on 8.5 (and the second on 8.4
        // as well), and the starred ones on both.
        $table = [
            ['name-list-other', 'localhost', false],      // the list names another server; the common name is right
            ['name-list-address', 'localhost', false],    // the list holds only an address; the common name is right
            ['name-star-alone', 'localhost', false],      // a bare star
            ['name-star-inside', 'localhost', false],     // stars inside a label, and after the first label
            ['name-address-as-name', '127.0.0.1', false], // an address written as if it were a name
            ['name-list-address', '127.0.0.1', true],     // the control: an address in the list, asked for by address
            ['name-no-list', 'localhost', true],          // the control: no list at all, so the common name counts
        ];
        foreach ($table as [$scenario, $host, $accepted]) {
            $before = $t->count($scenario, 'auth', '', false);
            [$result] = $t->send($t->transport($scenario, ['host' => $host]), build(plainSpec($now)));
            if ($accepted === true) {
                $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, $scenario . ' as ' . $host . ':');
                continue;
            }
            $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE, null, $scenario . ' as ' . $host . ':');
            $t->check(($result->certificate->reasons ?? []) === [CertificateFacts::WRONG_NAME], $scenario . ': the one reason is the wrong name: ' . implode(', ', $result->certificate->reasons ?? ['no facts']));
            $t->check(SendOptions::isFingerprint($result->certificate->sha256 ?? '') === true, $scenario . ': the facts carry the fingerprint');
            $t->check($t->count($scenario, 'auth') === $before && preg_grep('/^1 line /', $t->story($scenario)) === [], $scenario . ': no sign-in, and nothing at all said after encryption');
        }
    });

    $t->proof('L6w', 'A short key, an old signature and a certificate not meant for a server are each refused for their own reason', static function () use ($t, $now): void {
        // Each is in date, rightly named and signed by the trusted authority,
        // so "cannot be traced to an authority" would be untrue of all three.
        $table = [
            'odd-weak-key' => [CertificateFacts::WEAK_KEY, 'key is too short', '1024 bits'],
            'odd-sha1' => [CertificateFacts::WEAK_SIGNATURE, 'signed with an old method', 'SHA1'],
            'odd-client-only' => [CertificateFacts::NOT_FOR_A_SERVER, 'not marked for use by a server', 'Client Authentication'],
        ];
        foreach ($table as $scenario => [$reason, $words, $detail]) {
            // Read straight from the file (no network): what the certificate shows.
            $facts = CertificateFacts::fromCertificate(openssl_x509_read((string) file_get_contents($t->dir . '/certs/' . $scenario . '.pem')), '127.0.0.1', $now);
            $t->check($facts !== null && $facts->reasons === [] && array_keys($facts->weaknesses) === [$reason], $scenario . ': the certificate shows no certain fault and the one weakness "' . $reason . '" (it shows: ' . implode(', ', array_keys($facts->weaknesses ?? [])) . ')');
            // Over the wire: refused, and for that reason.
            [$result] = $t->send($t->transport($scenario), build(plainSpec($now)));
            $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE, null, $scenario . ':');
            $t->check(($result->certificate->reasons ?? []) === [$reason], $scenario . ': the one reason given is "' . $reason . '": ' . implode(', ', $result->certificate->reasons ?? ['no facts']));
            $t->check(strpos($result->serverWords, $words) !== false && stripos($result->serverWords, $detail) !== false, $scenario . ': the plain words say so: ' . $result->serverWords);
            $t->check(strpos($result->serverWords, 'cannot be traced') === false, $scenario . ': and do not say it cannot be traced to an authority');
            $t->check($t->count($scenario, 'auth') === 0, $scenario . ': no sign-in was sent');
        }
        // A good certificate shows none of the three, and the leftover reason still exists for what is left over.
        $good = CertificateFacts::fromCertificate(openssl_x509_read((string) file_get_contents($t->dir . '/certs/good.pem')), '127.0.0.1', $now);
        $t->check($good !== null && $good->weaknesses === [] && $good->withLikelyCause()->reasons === [CertificateFacts::UNKNOWN_ISSUER], 'a certificate that shows nothing wrong is given the leftover reason, in words that say "most often"');
        $t->check(strpos(implode(' ', $good?->withLikelyCause()->inWords() ?? []), 'Most often') !== false, 'the leftover sentence does not claim to know');
    });

    $t->proof('L15a', 'The certificate reader names the right fault for each certificate, and the server sees nothing after the handshake', static function () use ($t): void {
        $expected = [
            'cert-expired' => [SmtpConfig::STARTTLS, [CertificateFacts::EXPIRED]],
            'cert-expired-implicit' => [SmtpConfig::TLS, [CertificateFacts::EXPIRED]],
            'cert-notyet' => [SmtpConfig::STARTTLS, [CertificateFacts::NOT_YET_VALID]],
            'cert-wrongname' => [SmtpConfig::STARTTLS, [CertificateFacts::WRONG_NAME]],
            'cert-selfsigned' => [SmtpConfig::STARTTLS, [CertificateFacts::SELF_SIGNED]],
            // The reader itself finds nothing wrong with this one: "unknown issuer" is what is left over.
            'cert-privateca' => [SmtpConfig::STARTTLS, []],
        ];
        foreach ($expected as $scenario => [$security, $reasons]) {
            $before = $t->count($scenario, 'connect');
            $reader = new CertificateReader($t->config($scenario, ['security' => $security]), new SystemClock());
            $facts = $reader->read();
            $t->check($facts !== null && $facts->reasons === $reasons, $scenario . ': the reader found [' . implode(', ', $facts->reasons ?? ['nothing at all']) . ']');
            $t->check($facts !== null && $facts->serverName === '127.0.0.1' && $facts->names !== [], $scenario . ': the facts name the server and the certificate\'s names');
            $own = $t->story($scenario, $before + 1);
            $t->check(in_array('1 tls ok', $own, true) === true, $scenario . ': the reading connection did encrypt');
            $t->check(preg_grep('/^1 (line|auth|body) /', $own) === [], $scenario . ': the server saw NOTHING after the handshake: ' . implode(' | ', $own));
        }
    });

    $t->proof('L15b', 'Reading a certificate\'s names, dates and signature gives the right faults (no network)', static function () use ($t, $now): void {
        $read = static fn (string $name, string $server, int $at): ?CertificateFacts =>
            CertificateFacts::fromCertificate(openssl_x509_read((string) file_get_contents($t->dir . '/certs/' . $name . '.pem')), $server, $at);
        $t->check($read('good', 'localhost', $now)?->reasons === [], 'a good certificate, asked for by name: no fault');
        $t->check($read('good', '127.0.0.1', $now)?->reasons === [], 'a good certificate, asked for by address: no fault');
        $t->check($read('good', 'elsewhere.test', $now)?->reasons === [CertificateFacts::WRONG_NAME], 'a good certificate for another server: wrong name');
        // The clock decides, not the machine: the same certificate judged a year on has expired.
        $t->check($read('good', 'localhost', $now + 400 * 86400)?->reasons === [CertificateFacts::EXPIRED], 'the date is judged against the clock handed in');
        $t->check($read('good', 'localhost', $now - 400 * 86400)?->reasons === [CertificateFacts::NOT_YET_VALID], 'and a year before, it is not yet valid');
        $t->check($read('wrongname', 'mail.wild.test', $now)?->reasons === [], 'a starred name covers one label');
        $t->check($read('selfsigned', 'other.test', $now + 400 * 86400)?->reasons === [CertificateFacts::EXPIRED, CertificateFacts::WRONG_NAME, CertificateFacts::SELF_SIGNED], 'several faults are all listed');

        $matches = [
            ['mail.example.test', ['mail.example.test'], true], ['MAIL.Example.TEST', ['mail.example.test'], true],
            ['mail.example.test', ['*.example.test'], true], ['example.test', ['*.example.test'], false],
            ['a.mail.example.test', ['*.example.test'], false], ['mail.example.test', ['*.test'], false],
            ['mail.example.test', ['other.test', 'mail.example.test'], true], ['mail.example.test', [], false],
            ['127.0.0.1', ['*.0.0.1'], false], ['', ['mail.example.test'], false],
            ['mail.example.test', ['mail.example.test.evil.test'], false], ['evilmail.example.test', ['mail.example.test'], false],
            // A star only as the WHOLE first label (RFC 7817 section 3). PHP's own check accepts every one of these five.
            ['mail.example.test', ['m*.example.test'], false], ['mail.example.test', ['*il.example.test'], false],
            ['mail.example.test', ['ma*l.example.test'], false], ['localhost', ['*'], false], ['mail.example.test', ['*.*.test'], false],
            ['mail.example.test', ['mail.*'], false], ['mail.example.test', ['mail.*.test'], false], ['mail.example.test', ['*.mail.example.test'], false],
            ['mail.example.test', ['*.EXAMPLE.test.'], true], ['mail.example.test.', ['mail.example.test'], true],
            ['mail.example.test', ["mail.example.test\0.evil.test"], false],
        ];
        foreach ($matches as [$server, $names, $should]) {
            $t->check(CertificateFacts::nameMatches($server, $names) === $should, '"' . $server . '" against [' . implode(', ', $names) . '] should ' . ($should === true ? '' : 'NOT ') . 'match');
        }
        // An ADDRESS is compared only with the addresses in the list, a NAME only with the names.
        $kinds = [
            ['127.0.0.1', [], ['127.0.0.1'], true], ['127.0.0.1', ['127.0.0.1'], [], false], ['127.0.0.1', ['*'], ['127.0.0.2'], false],
            ['::1', [], ['0:0:0:0:0:0:0:1'], true], ['mail.example.test', [], ['127.0.0.1'], false], ['mail.example.test', ['mail.example.test'], ['127.0.0.1'], true],
        ];
        foreach ($kinds as [$server, $names, $addresses, $should]) {
            $t->check(CertificateFacts::nameMatches($server, $names, $addresses) === $should, '"' . $server . '" against names [' . implode(', ', $names) . '] and addresses [' . implode(', ', $addresses) . '] should ' . ($should === true ? '' : 'NOT ') . 'match');
        }
        // The old "common name" counts only when the certificate has NO list of names at all.
        $t->check($read('name-no-list', 'localhost', $now)?->reasons === [], 'no list of names: the common name counts');
        $t->check($read('name-list-other', 'localhost', $now)?->reasons === [CertificateFacts::WRONG_NAME], 'a list for another server: the common name does not count');
        $t->check($read('name-list-address', 'localhost', $now)?->reasons === [CertificateFacts::WRONG_NAME], 'a list holding only an address: the common name does not count either');
        $t->check($read('name-list-address', '127.0.0.1', $now)?->reasons === [], 'and that same certificate is right for the address');
        $facts = $read('expired', 'elsewhere.test', $now);
        $sentences = implode(' ', $facts?->withReason(CertificateFacts::NOT_APPROVED)->inWords() ?? []);
        $t->check(strpos($sentences, 'expired on') !== false && strpos($sentences, '(UTC)') !== false && strpos($sentences, 'elsewhere.test') !== false && strpos($sentences, 'different certificate') !== false, 'the plain sentences name the date in UTC, the names and the approval: ' . $sentences);
        $t->check(CertificateFacts::clean("a\r\nb\0c\x7F") === 'a??b?c?', 'words taken from a certificate lose their control characters');
    });

    // -------------------------------------------------------------------------
    // L7, L8: no encryption on offer, and text injected before it
    // -------------------------------------------------------------------------

    $t->proof('L7a', 'A server that does not offer STARTTLS gets no sign-in and no message, in the clear or otherwise', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('no-starttls'), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_STARTTLS, SendResult::REASON_NO_ENCRYPTION);
        $t->check($t->story('no-starttls') === ['0 line EHLO client.test'], 'the server saw the introduction and nothing else: ' . implode(' | ', $t->story('no-starttls')));
    });

    $t->proof('L7b', 'A server that offers STARTTLS and then refuses it gets no sign-in', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('starttls-refused'), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_STARTTLS, SendResult::REASON_SERVER_SAID_LATER, 454);
        $t->check($t->story('starttls-refused') === ['0 line EHLO client.test', '0 line STARTTLS'], 'the server saw EHLO and STARTTLS only: ' . implode(' | ', $t->story('starttls-refused')));
    });

    $t->proof('L7c', 'A port that speaks no encryption at all is refused, with no certificate to approve', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('plain-only', ['security' => SmtpConfig::TLS]), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_NO_ENCRYPTION);
        $t->check($result->certificate === null, 'no certificate facts are offered, so there is nothing a person could approve');
        $t->check(preg_grep('/^\d (auth|body) |MAIL|EHLO/', $t->story('plain-only')) === [], 'the server was told nothing: ' . implode(' | ', $t->story('plain-only')));
    });

    $t->proof('L8', 'Text slipped in after the server agrees to encrypt ends the connection: no sign-in follows', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('inject'), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_INJECTED_TEXT);
        $t->check($t->count('inject', 'auth') === 0 && $t->count('inject', 'line', 'MAIL') === 0, 'no sign-in and no message reached the server');
        $t->check($t->count('inject', 'connect') === 1, 'no second connection was made: the certificate was never the question');
    });

    // -------------------------------------------------------------------------
    // L9: servers that go silent. Each must end inside its limit, measured.
    // -------------------------------------------------------------------------

    $stalls = [
        'L9a' => ['never greets', 'stall-greeting', SmtpConfig::STARTTLS, ['replySeconds' => 2.0], SendResult::TRY_LATER, SendResult::STAGE_GREETING, 2.0],
        'L9b' => ['stalls in the encryption handshake after STARTTLS', 'stall-handshake', SmtpConfig::STARTTLS, ['handshakeSeconds' => 2.0], SendResult::TRY_LATER, SendResult::STAGE_TLS, 2.0],
        'L9c' => ['stalls in the encryption handshake from the first byte', 'stall-handshake-implicit', SmtpConfig::TLS, ['handshakeSeconds' => 2.0], SendResult::TRY_LATER, SendResult::STAGE_TLS, 2.0],
        'L9d' => ['does not answer the sign-in', 'stall-signin', SmtpConfig::STARTTLS, ['replySeconds' => 2.0], SendResult::TRY_LATER, SendResult::STAGE_AUTH, 2.0],
        'L9e' => ['does not answer a command part way through a message', 'stall-command', SmtpConfig::STARTTLS, ['replySeconds' => 2.0], SendResult::TRY_LATER, SendResult::STAGE_RECIPIENT, 2.0],
        'L9f' => ['sends a reply one byte at a time, never finishing it', 'drip', SmtpConfig::STARTTLS, ['replySeconds' => 2.0], SendResult::TRY_LATER, SendResult::STAGE_SENDER, 2.0],
        'L9g' => ['answers every step, but too slowly for the message as a whole', 'slow', SmtpConfig::STARTTLS, ['messageSeconds' => 3.0], SendResult::TRY_LATER, null, 3.0],
    ];
    foreach ($stalls as $id => [$label, $scenario, $security, $limits, $outcome, $stage, $limit]) {
        $t->proof($id, 'A server that ' . $label . ' is given up on inside the limit', static function () use ($t, $now, $scenario, $security, $limits, $outcome, $stage, $limit, $label): void {
            [$result, $seconds] = $t->send($t->transport($scenario, $limits + ['security' => $security]), build(plainSpec($now)));
            $t->expect($result, $outcome, $stage ?? $result->stage, SendResult::REASON_TIMEOUT);
            $t->within($seconds, $limit, 'a server that ' . $label);
            $t->check($t->count($scenario, 'body', '', false) === 0, 'no message reached the server');
        });
    }

    $t->proof('L9h', 'A server that stops READING in the middle of a message, over TLS, is given up on inside the write limit', static function () use ($t, $now): void {
        // 9 MB, far more than any socket will hold, so the writes must back up.
        $spec = plainSpec($now, ['attachments' => [['name' => 'big.bin', 'content' => str_repeat(random_bytes(1024), 9000)]]]);
        $message = build($spec);
        $message->size(); // built before the clock starts
        [$result, $seconds] = $t->send($t->transport('stall-reading', ['writeSeconds' => 2.0]), $message);
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_BODY, SendResult::REASON_TIMEOUT);
        $t->within($seconds, 2.0, 'a server that stopped reading mid-message');
        $t->check(strpos(implode("\n", $result->transcript), 'Encryption is on (TLSv1.') !== false, 'this was over an encrypted connection');
        $t->check($t->count('stall-reading', 'body', 'stopped reading on purpose', false) === 1, 'the server really had stopped reading');
    });

    $t->proof('L9i', 'A server that takes the whole message and then never answers: nobody knows, so a person must look', static function () use ($t, $now): void {
        [$result, $seconds] = $t->send($t->transport('stall-verdict', ['bodyReplySeconds' => 2.0]), build(plainSpec($now)));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_BODY, SendResult::REASON_UNKNOWN_IF_SENT);
        $t->within($seconds, 2.0, 'a server silent after the message');
        $t->check($t->count('stall-verdict', 'body', '', false) === 1, 'the server did receive the whole message, exactly once (it was not sent again)');
    });

    // -------------------------------------------------------------------------
    // L10: servers that hang up
    // -------------------------------------------------------------------------

    $t->proof('L10a', 'The connection dropped in the middle of a message is never reported as sent', static function () use ($t, $now): void {
        $message = build(plainSpec($now, ['attachments' => [['name' => 'big.bin', 'content' => str_repeat(random_bytes(1024), 9000)]]]));
        $transport = $t->transport('drop-mid');
        [$result] = $t->send($transport, $message);
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_BODY, SendResult::REASON_DROPPED);
        $t->check($t->count('drop-mid', 'body', 'bytes=', false) === 1 && preg_grep('/complete=1/', $t->story('drop-mid')) === [], 'the server got only part of the message');
        // 🧭 A known state afterwards: the next send starts a fresh connection.
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->check($t->count('drop-mid', 'connect', '', false) === 2, 'after the drop the route made a new connection for the next message');
    });

    $t->proof('L10b', 'The connection dropped after the whole message was handed over: a person must look, and it is not sent twice', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('drop-after'), build(plainSpec($now)));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_BODY, SendResult::REASON_UNKNOWN_IF_SENT);
        $t->check($t->count('drop-after', 'body') === 1 && $t->count('drop-after', 'connect') === 1, 'one connection, one copy of the message');
    });

    $t->proof('L10c', 'A fault inside the client AFTER the message was handed over is "nobody knows", never "not sent"', static function () use ($t, $now): void {
        // "Not sent, try later" here would mean a second copy: the server has
        // the whole message and may well have taken it.
        $clock = new BreakingClock('after-the-message');
        $transport = $t->transport('after-handover', [], $clock);
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->check($clock->broke === true, 'the clock did break, once the message had been written');
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_BODY, SendResult::REASON_UNKNOWN_IF_SENT);
        $t->check(strpos($result->serverWords, 'the clock broke on purpose') !== false, 'the fault came back as a result, not as an exception: ' . $result->serverWords);
        $t->check($t->count('after-handover', 'body', 'bytes=') === 1 && preg_grep('/complete=1/', $t->story('after-handover')) !== [], 'the server did receive the whole message, once');
        // And a fault BEFORE the hand-over is still "try later": nothing can have been sent.
        $clock = new BreakingClock('during-sign-in');
        [$result] = $t->send($t->transport('after-handover', [], $clock), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_AUTH, SendResult::REASON_INTERNAL, null, 'a fault during the sign-in:');
        $t->check($t->count('after-handover', 'body', 'bytes=') === 1, 'and no second message reached the server');
    });

    // -------------------------------------------------------------------------
    // L11: the server says no to a sender or a recipient
    // -------------------------------------------------------------------------

    $t->proof('L11', 'A refused recipient or sender is reported in the server\'s words, nothing is sent, and the connection stays usable', static function () use ($t, $now): void {
        $transport = $t->transport('refusals');
        [$result] = $t->send($transport, build(plainSpec($now, ['to' => ['reader@standin.test', 'nobody@standin.test']])));
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_RECIPIENT, SendResult::REASON_SERVER_SAID_NO, 550, 'one of two recipients refused:');
        $t->check($result->recipientReplies === ['nobody@standin.test' => '550 5.1.1 no such mailbox here'], 'the refused recipient and the server\'s reply are listed');
        $t->check($result->isAboutTheMessage() === true, 'this refusal is about the message');
        $t->check($t->count('refusals', 'line', 'DATA', false) === 0, 'the message went to NOBODY: not even the recipient who was accepted');

        [$result] = $t->send($transport, build(plainSpec($now, ['to' => ['busy@standin.test']])));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_RECIPIENT, SendResult::REASON_SERVER_SAID_LATER, 450, 'a recipient the server says to try later:');
        [$result] = $t->send($transport, build(plainSpec($now, ['to' => ['busy@standin.test', 'nobody@standin.test']])));
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_RECIPIENT, SendResult::REASON_SERVER_SAID_NO, 450, 'one "later" and one "no" together:');
        [$result] = $t->send($transport, build(plainSpec($now, ['fromAddress' => 'blocked@standin.test'])));
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_SENDER, SendResult::REASON_SERVER_SAID_NO, 550, 'a refused sender:');
        $t->check(strpos($result->serverWords, 'not allowed to send as that address') !== false, "the server's words about the sender are kept");
        [$result] = $t->send($transport, build(plainSpec($now, ['fromAddress' => 'later@standin.test'])));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_SENDER, SendResult::REASON_SERVER_SAID_LATER, 451, 'a sender the server says to try later:');
        $t->check($t->count('refusals', 'body', '', false) === 0, 'after five refusals, not one message has been sent');

        // 🧭 A known state: the same connection now carries a good message.
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'a good message after the refusals:');
        $t->check($t->count('refusals', 'connect', '', false) === 1 && $t->count('refusals', 'body', '', false) === 1, 'all of it on ONE connection, with one message delivered');
    });

    // -------------------------------------------------------------------------
    // L12: the password appears nowhere
    // -------------------------------------------------------------------------

    $t->proof('L12b', 'A server that repeats back what it was sent does not get the password into any result or log', static function () use ($t, $now): void {
        foreach (['echo', 'echo-login'] as $scenario) {
            $log = new MemoryLog();
            $transport = new SmtpTransport($t->config($scenario), null, $log);
            [$result] = $t->send($transport, build(plainSpec($now)));
            $t->expect($result, SendResult::REFUSED, SendResult::STAGE_AUTH, SendResult::REASON_SERVER_SAID_NO, 500);
            $all = dumpEveryWay($result) . implode("\n", $log->lines) . dumpEveryWay($transport);
            $t->check(strpos($all, 'not understood: ') !== false, $scenario . ': the server really did repeat the sign-in line back');
            $t->check(strpos($all, Secret::HIDDEN) !== false, $scenario . ': the repeated line was replaced');
            $t->check(findSecret($all) === '', $scenario . ': ' . findSecret($all));
        }
    });

    $t->proof('L12f', "The password repeated in a recipient's refusal, in the server's list of offers, or in a fault's own words is removed from all of them", static function () use ($t, $now): void {
        // Three places a password could reach a result without passing the
        // sign-in reply: each is covered by its own remover, and each part of
        // this proof fails when its remover is taken out.
        $log = new MemoryLog();
        $transport = new SmtpTransport($t->config('echo-recipient'), null, $log);
        [$result] = $t->send($transport, build(plainSpec($now, ['to' => ['reader@standin.test', 'nobody@standin.test']])));
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_RECIPIENT, SendResult::REASON_SERVER_SAID_NO, 550);
        $reply = $result->recipientReplies['nobody@standin.test'] ?? '';
        $t->check(strpos($reply, 'no such mailbox; you signed in with') !== false, "the server's refusal of the recipient is kept: " . $reply);
        $t->check(strpos($reply, Secret::HIDDEN) !== false && findSecret($reply) === '', "the password is gone from the recipient's reply: " . findSecret($reply));
        $t->check(findSecret(implode("\n", $result->transcript)) === '' && findSecret($result->serverWords) === '', 'and from the transcript and the words');
        // What the server offers is kept inside the route in CAPITALS, so it is searched for without regard to case.
        $kept = dumpEveryWay($transport);
        $t->check(strpos($kept, 'X-ECHO') !== false, 'the list of what the server offers is in the dump, so the search looks in the right place');
        $t->check(stripos($kept, TEST_PASS) === false, 'the password is not kept in the list of offers, in any case of letters');
        $t->check(findSecret($kept . implode("\n", $log->lines) . dumpEveryWay($result)) === '', findSecret($kept . implode("\n", $log->lines) . dumpEveryWay($result)));
        $transport->close();

        // A fault whose own words hold the password (nothing real does this; it shows the last remover works alone).
        // (This route is made by hand and not through the workbench: the
        // workbench dumps every route it made into the text searched at the
        // end, and this one's clock holds the password on purpose.)
        $clock = new BreakingClock('during-sign-in', 'a fault that says ' . TEST_PASS . ' out loud');
        $faulty = new SmtpTransport($t->config('fault-words'), $clock, $t->log);
        [$result] = $t->send($faulty, build(plainSpec($now)));
        $faulty->close();
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_AUTH, SendResult::REASON_INTERNAL);
        $t->check(strpos($result->serverWords, 'a fault that says ' . Secret::HIDDEN) !== false && findSecret($result->serverWords) === '', "the password is gone from the fault's words: " . findSecret($result->serverWords));
    });

    $t->proof('L12c', 'A Secret cannot be printed, dumped, exported, cast, turned into JSON, saved or copied', static function () use ($t): void {
        $secret = new Secret(TEST_PASS);
        $t->check($secret->reveal() === TEST_PASS && $secret->isEmpty() !== true, 'the password can be taken out on purpose');
        $shown = dumpEveryWay($secret) . dumpEveryWay([$secret, 'holder' => new SmtpConfig('mail.standin.test', 587, 'starttls', TEST_USER, $secret)]) . 'in a string: ' . $secret;
        $t->check(findSecret($shown) === '', findSecret($shown));
        $t->check(strpos($shown, 'cannot be saved') !== false, 'saving one is refused outright');
        $t->check((new ReflectionMethod($secret, '__clone'))->isPrivate() === true, 'one cannot be copied');
        // Removing it from text, whole and in pieces.
        $plain = base64_encode("\0" . TEST_USER . "\0" . TEST_PASS);
        $texts = [
            'the password itself' => 'you sent ' . TEST_PASS . ' to me',
            'its base64 form' => '334 ' . base64_encode(TEST_PASS),
            'the PLAIN sign-in line' => '500 not understood: AUTH PLAIN ' . $plain,
            'the first half of the sign-in line' => '500 line too long: AUTH PLAIN ' . substr($plain, 0, 40),
            'the middle of the sign-in line' => '500 bad: ' . substr($plain, 20, 30) . ' <-',
            'twelve characters of the base64 form' => 'partial ' . substr(base64_encode(TEST_PASS), 3, 12) . ' end',
        ];
        foreach ($texts as $label => $text) {
            $clean = $secret->scrub($text, "\0" . TEST_USER . "\0");
            $t->check(findSecret($clean) === '' && strpos($clean, Secret::HIDDEN) !== false, 'removing ' . $label . ': ' . findSecret($clean));
        }
        $t->check($secret->scrub('250 2.0.0 nothing secret here') === '250 2.0.0 nothing secret here', 'ordinary text is left exactly as it was');
        $secret->wipe();
        $t->check($secret->reveal() === '' && $secret->isEmpty() === true, 'after wipe() the wrapper is empty');
        $t->check((new SmtpConfig('mail.standin.test', 587, 'starttls', TEST_USER, $secret))->missing() === ['No password is stored.'], 'a wiped password counts as "not set up"');
    });

    $t->proof('L12d', 'The password is in no stack trace: not when all goes well, and not when something breaks during the sign-in', static function () use ($t, $now): void {
        foreach ([false, true] as $break) {
            $clock = new SpyClock($break);
            [$result] = $t->send($t->transport('traces', [], $clock), build(plainSpec($now)));
            if ($break === true) {
                $t->check($clock->broke === true, 'the clock did break in the middle of the sign-in');
                $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_AUTH, SendResult::REASON_INTERNAL);
                $t->check(strpos($result->serverWords, 'the clock broke on purpose') !== false, 'the fault came back as a result, not as an exception');
            } else {
                $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250);
            }
            // The traces must really hold arguments, or finding no password in them proves nothing.
            $t->check(strpos($clock->seen, 'AUTH PLAIN ' . Secret::HIDDEN) !== false, 'the traces do carry arguments (the replaced sign-in line is among them)');
            $t->check(strpos($clock->seen, 'SensitiveParameterValue') !== false, 'the sign-in line itself shows as a hidden value');
            $t->check(findSecret($clock->seen) === '', findSecret($clock->seen));
            $t->haystack .= $clock->seen;
        }
    });

    // -------------------------------------------------------------------------
    // L13: one connection, many messages
    // -------------------------------------------------------------------------

    $t->proof('L13a', 'Twenty messages go down one connection, each cleared before the next', static function () use ($t, $now): void {
        $transport = $t->transport('twenty');
        for ($i = 1; $i <= 20; $i++) {
            [$result] = $t->send($transport, build(plainSpec($now, ['subject' => 'Message number ' . $i])));
            $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'message ' . $i . ':');
        }
        $transport->close();
        $t->check($t->count('twenty', 'connect') === 1, 'one connection: ' . $t->count('twenty', 'connect'));
        $t->check($t->count('twenty', 'auth') === 1, 'one sign-in');
        $t->check($t->count('twenty', 'body', 'bytes=') === 20, 'twenty messages received');
        $t->check($t->count('twenty', 'line', 'RSET') === 19, 'the connection was cleared before each message after the first');
    });

    $t->proof('L13b', 'A server that closes the connection between messages is reconnected to, once', static function () use ($t, $now): void {
        $transport = $t->transport('closes-between');
        foreach ([1, 2, 3] as $i) {
            [$result] = $t->send($transport, build(plainSpec($now)));
            $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'message ' . $i . ':');
            if ($i === 2) {
                $t->check(strpos(implode("\n", $result->transcript), 'Connecting again, once.') !== false, 'the transcript says the connection had to be made again');
            }
        }
        $t->check($t->count('closes-between', 'connect', '', false) === 3 && $t->count('closes-between', 'body', '', false) === 3, 'three messages, each needing a new connection, none lost and none doubled');
    });

    $t->proof('L13c', 'After two failures to connect, the rest of a batch comes back "try later" in seconds', static function () use ($t, $now): void {
        $client = new Client($t->transport('dead', ['replySeconds' => 2.0]));
        $started = microtime(true);
        $outcomes = [];
        for ($i = 1; $i <= 20; $i++) {
            [$result] = $t->send($client, build(plainSpec($now)));
            $outcomes[] = $result->outcome . '/' . $result->reason;
        }
        $seconds = microtime(true) - $started;
        $t->check(array_slice($outcomes, 0, 2) === ['try-later/timeout', 'try-later/timeout'], 'the first two were tried and timed out: ' . implode(', ', array_slice($outcomes, 0, 2)));
        $t->check(array_unique(array_slice($outcomes, 2)) === ['try-later/not-attempted'], 'the other eighteen were not attempted: ' . implode(', ', array_unique(array_slice($outcomes, 2))));
        $t->check($t->count('dead', 'connect', '', false) === 2, 'the server was connected to twice, not twenty times');
        $t->check($seconds < 2 * 2.0 + SLACK, sprintf('twenty messages to a dead server took %.1fs (twenty time limits would be 40s)', $seconds));
        $t->say(sprintf('measured: twenty messages to a dead server took %.2fs', $seconds));
    });

    $t->proof('L13d', 'A connection is closed and replaced after its fill of messages', static function () use ($t, $now): void {
        $transport = $t->transport('three-each', ['messagesPerConnection' => 3]);
        for ($i = 1; $i <= 7; $i++) {
            [$result] = $t->send($transport, build(plainSpec($now)));
            $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'message ' . $i . ':');
        }
        $transport->close();
        $t->check($t->count('three-each', 'connect') === 3 && $t->count('three-each', 'line', 'QUIT') === 3, 'seven messages at three a connection: three connections, each ended politely');
    });

    $t->proof('X4', 'A second route is tried only when the first could not be reached, never after a refusal', static function () use ($t, $now): void {
        $unreachable = new SmtpTransport(new SmtpConfig('127.0.0.1', closedPort(), 'starttls', TEST_USER, new Secret(TEST_PASS)), null, $t->log);
        $second = $t->transport('second-route');
        [$result] = $t->send(new Client($unreachable, $second), build(plainSpec($now)));
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'first route unreachable:');
        [$direct] = $t->send($unreachable, build(plainSpec($now)));
        $t->expect($direct, SendResult::TRY_LATER, SendResult::STAGE_CONNECT, SendResult::REASON_CANNOT_CONNECT, null, 'the unreachable route by itself:');
        $t->check($direct->isConnectionFailure() === true, 'that is counted as a connection failure');

        $refusing = $t->transport('refuse-signin', ['password' => WRONG_PASS]);
        [$result] = $t->send(new Client($refusing, $second), build(plainSpec($now)));
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_AUTH, SendResult::REASON_SERVER_SAID_NO, 535, 'first route refuses:');
        $t->check($t->count('second-route', 'body', '', false) === 1, 'the second route carried the one message it should, and not the refused one');

        $none = new Client($t->transport('accept-starttls', ['security' => 'none']));
        [$result] = $t->send($none, build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_CONFIG, SendResult::REASON_NOT_SET_UP, null, 'a route that is not set up:');
    });

    // -------------------------------------------------------------------------
    // L14: each outcome is the right one of four
    // -------------------------------------------------------------------------

    $t->proof('L14a', 'A reply beginning 4 is "try later" and one beginning 5 is "refused", at every stage', static function () use ($t, $now): void {
        $table = [
            ['greeting-no', SendResult::REFUSED, SendResult::STAGE_GREETING, SendResult::REASON_SERVER_SAID_NO, 554, 'no mail service here'],
            ['greeting-closing', SendResult::TRY_LATER, SendResult::STAGE_GREETING, SendResult::REASON_SERVER_SAID_LATER, 421, 'shutting down'],
            ['data-later', SendResult::TRY_LATER, SendResult::STAGE_DATA, SendResult::REASON_SERVER_SAID_LATER, 452, 'out of space for now'],
            ['data-no', SendResult::REFUSED, SendResult::STAGE_DATA, SendResult::REASON_SERVER_SAID_NO, 554, 'no messages taken'],
            ['body-later', SendResult::TRY_LATER, SendResult::STAGE_BODY, SendResult::REASON_SERVER_SAID_LATER, 451, 'could not store it'],
            ['body-no', SendResult::REFUSED, SendResult::STAGE_BODY, SendResult::REASON_SERVER_SAID_NO, 552, 'too big for me'],
            ['body-closing', SendResult::TRY_LATER, SendResult::STAGE_BODY, SendResult::REASON_SERVER_SAID_LATER, 421, 'closing, try again'],
        ];
        foreach ($table as [$scenario, $outcome, $stage, $reason, $code, $words]) {
            $transport = $t->transport($scenario);
            [$result] = $t->send($transport, build(plainSpec($now)));
            $t->expect($result, $outcome, $stage, $reason, $code, $scenario . ':');
            $t->check(strpos($result->serverWords, $words) !== false, $scenario . ": the server's own words are kept: " . $result->serverWords);
            $t->check($result->accepted() !== true, $scenario . ': not reported as sent');
        }
        // "421" means the server is closing: the next message needs a new connection. Any other "later" keeps it.
        $transport = $t->transport('body-closing');
        $t->send($transport, build(plainSpec($now)));
        $before = $t->count('body-closing', 'connect', '', false);
        $t->send($transport, build(plainSpec($now)));
        $t->check($t->count('body-closing', 'connect', '', false) === $before + 1, 'after a 421 the route connects afresh');
        $transport = $t->transport('body-later');
        $t->send($transport, build(plainSpec($now)));
        $before = $t->count('body-later', 'connect', '', false);
        $t->send($transport, build(plainSpec($now)));
        $t->check($t->count('body-later', 'connect', '', false) === $before, 'after an ordinary "try later" the same connection is used again');
    });

    $t->proof('L14b', 'A reply that does not belong at its stage is a failure with the server\'s words kept, never a guessed success', static function () use ($t, $now): void {
        $table = [
            ['odd-greeting', SendResult::TRY_LATER, SendResult::STAGE_GREETING, 'hello, what do you want?'],
            ['odd-ehlo', SendResult::TRY_LATER, SendResult::STAGE_EHLO, 'go ahead and type'],
            ['odd-mixed', SendResult::TRY_LATER, SendResult::STAGE_EHLO, '251 STARTTLS'],
            ['odd-starttls', SendResult::TRY_LATER, SendResult::STAGE_STARTTLS, 'fine by me'],
            ['odd-signin', SendResult::TRY_LATER, SendResult::STAGE_AUTH, 'welcome anyway'],
            ['odd-sender', SendResult::TRY_LATER, SendResult::STAGE_SENDER, 'keep typing'],
            ['odd-recipient', SendResult::TRY_LATER, SendResult::STAGE_RECIPIENT, 'hello again'],
            ['odd-data', SendResult::TRY_LATER, SendResult::STAGE_DATA, 'done already'],
            // After the message was handed over, an answer that makes no sense leaves nobody knowing.
            ['odd-verdict', SendResult::NEEDS_PERSON, SendResult::STAGE_BODY, 'thanks, got it'],
            ['odd-verdict-number', SendResult::NEEDS_PERSON, SendResult::STAGE_BODY, 'perhaps'],
        ];
        foreach ($table as [$scenario, $outcome, $stage, $words]) {
            [$result] = $t->send($t->transport($scenario), build(plainSpec($now)));
            $reason = $outcome === SendResult::NEEDS_PERSON ? SendResult::REASON_UNKNOWN_IF_SENT : SendResult::REASON_UNEXPECTED;
            $t->expect($result, $outcome, $stage, $reason, null, $scenario . ':');
            $t->check(strpos($result->serverWords, $words) !== false, $scenario . ": the server's own words are kept: " . $result->serverWords);
            // Up to the DATA command nothing may have been sent; and the odd answer to the sign-in must not be taken as "signed in".
            if ($outcome === SendResult::TRY_LATER) {
                $t->check($t->count($scenario, 'body') === 0, $scenario . ': no message was sent on the strength of an answer it did not understand');
            }
        }
        $t->check($t->count('odd-signin', 'line', 'MAIL') === 0, 'a sign-in answered "250" instead of "235" is not treated as signed in');
        $t->check($t->count('odd-starttls', 'auth') === 0, 'a STARTTLS answered "250" instead of "220" is not followed by a sign-in');
    });

    $t->proof('L14c', 'A server that speaks when it should be listening is out of step: closed, and never taken as "accepted"', static function () use ($t, $now): void {
        // "Go ahead" and an early "250" together; after the message it would say "554 rejected".
        $transport = $t->transport('early-verdict');
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_BODY, SendResult::REASON_UNEXPECTED, null, 'an answer before the message:');
        $t->check($result->accepted() !== true && preg_grep('/complete=1/', $t->story('early-verdict')) === [], 'not accepted, and the message was never written to the server');
        $t->check(strpos(implode("\n", $result->transcript), 'S (out of turn): 250 2.0.0 said too early') !== false, 'the transcript shows what was said out of turn');
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->check($t->count('early-verdict', 'connect', '', false) === 2, 'the connection was closed: the next message needed a new one');

        // An extra reply nobody asked for, waiting when the next command is due.
        [$result] = $t->send($t->transport('out-of-turn'), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_RECIPIENT, SendResult::REASON_UNEXPECTED, null, 'a reply nobody asked for:');
        $t->check(strpos($result->serverWords, 'spoke out of turn') !== false && strpos($result->serverWords, 'nobody asked') !== false, "the server's words are kept: " . $result->serverWords);
        $t->check($t->count('out-of-turn', 'line', 'RCPT') === 0 && $t->count('out-of-turn', 'line', 'DATA') === 0, 'nothing more was said to it: no recipient, no message');

        // 📏 "552 too big" part way through a message, then it hangs up: the 552 is kept.
        $big = build(plainSpec($now, ['attachments' => [['name' => 'big.bin', 'content' => str_repeat(random_bytes(1024), 9000)]]]));
        [$result] = $t->send($t->transport('refuse-mid'), $big);
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_BODY, SendResult::REASON_SERVER_SAID_NO, 552, 'refused part way through:');
        $t->check(strpos($result->serverWords, 'too big for this mailbox') !== false, "the server's own words are kept: " . $result->serverWords);
        $t->check(preg_grep('/complete=1/', $t->story('refuse-mid')) === [], 'the server never had the whole message');

        // The server says "250" while the message is still going out, then takes
        // the rest. That early "250" is not a verdict on a message it had not
        // finished reading: the whole message was handed over, and nobody knows.
        [$result] = $t->send($t->transport('speaks-early'), $big);
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_BODY, SendResult::REASON_UNKNOWN_IF_SENT, null, 'spoke while the message was going out:');
        $t->check($result->accepted() !== true && strpos($result->serverWords, 'spoke before it had all of it') !== false, 'not accepted, and the result says why: ' . $result->serverWords);
        $t->check(strpos(implode("\n", $result->transcript), 'S (out of turn): 250 2.0.0 said before') !== false, 'the transcript shows what was said out of turn');
        $t->check($t->count('speaks-early', 'note', 'spoke part way', false) === 1, 'the server really did speak part way through');

        // A reply line far longer than a reply may be, arriving all at once.
        [$result] = $t->send($t->transport('long-line'), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_GREETING, SendResult::REASON_UNEXPECTED, null, 'a 6000-byte reply line:');
        $t->check($t->count('long-line', 'line') === 0, 'nothing was said to that server');
    });

    // -------------------------------------------------------------------------
    // L16, L17: "this certificate only", and what it can never be stretched to
    // -------------------------------------------------------------------------

    $t->proof('L16a', '"This certificate only" is accepted for the fingerprint that was shown, and refused for another certificate on the same port', static function () use ($t, $now): void {
        // 1. The checked send fails and shows the certificate (connections 1 and 2).
        [$shown] = $t->send($t->transport('pin'), build(plainSpec($now)));
        $t->expect($shown, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE);
        $fingerprint = $shown->certificate->sha256 ?? '';
        // 2. Approved by fingerprint: this one send goes (connection 3).
        $transport = $t->transport('pin');
        [$result] = $t->send($transport, build(plainSpec($now)), SendOptions::onlyThisCertificate($fingerprint));
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'the approved certificate:');
        $t->check($t->count('pin', 'auth', 'PLAIN user=' . TEST_USER . ' matched=1', false) === 1 && $t->count('pin', 'body', '', false) === 1, 'the server was signed in to and received the message');
        // 3. 🔒 The approval was for ONE send. The next message, sent the
        //    normal way through the very same route, must not ride on the
        //    connection that was opened for the approved certificate.
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE, null, 'the next, unapproved message:');
        $t->check($t->count('pin', 'body', '', false) === 1, 'the unapproved message did NOT go out on the approved connection');
        // 4. The server now shows a different (also expired) certificate. The old approval must not cover it.
        [$result] = $t->send($t->transport('pin'), build(plainSpec($now)), SendOptions::onlyThisCertificate($fingerprint));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE, null, 'a different certificate on the same port:');
        $reasons = $result->certificate->reasons ?? [];
        $t->check(in_array(CertificateFacts::NOT_APPROVED, $reasons, true) === true, 'the reason says it is not the approved certificate: ' . implode(', ', $reasons));
        $t->check(($result->certificate->sha256 ?? '') !== $fingerprint, 'the facts show the certificate that was actually presented');
        $t->check($t->count('pin', 'body') === 1 && $t->count('pin', 'auth') === 1, 'in all, one sign-in and one message reached the server');

        // The same over encryption from the first byte.
        [$shown] = $t->send($t->transport('pin-implicit', ['security' => SmtpConfig::TLS]), build(plainSpec($now)));
        [$result] = $t->send($t->transport('pin-implicit', ['security' => SmtpConfig::TLS]), build(plainSpec($now)), SendOptions::onlyThisCertificate($shown->certificate->sha256 ?? ''));
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'approved, encrypted from the first byte:');
        [$result] = $t->send($t->transport('pin-implicit', ['security' => SmtpConfig::TLS]), build(plainSpec($now)), SendOptions::onlyThisCertificate(str_repeat('ab', 32)));
        $t->expect($result, SendResult::NEEDS_PERSON, SendResult::STAGE_TLS, SendResult::REASON_CERTIFICATE, null, 'a fingerprint that is not this certificate\'s:');
    });

    $t->proof('L16b', '"This certificate only" cannot be widened into "accept anything"', static function () use ($t): void {
        $whole = str_repeat('0123456789abcdef', 4);
        foreach (['', ' ', '*', 'any', 'sha256', substr($whole, 1), $whole . 'a', str_repeat('z', 64), implode(':', str_split($whole, 2)), $whole . "\n", "\0" . substr($whole, 1)] as $bad) {
            try {
                SendOptions::onlyThisCertificate($bad);
                $t->check(false, 'the value "' . addcslashes($bad, "\0..\37") . '" was accepted as a fingerprint');
            } catch (InvalidArgumentException) {
            }
            // And the layer below refuses it too, whoever calls it.
            try {
                new Connection($t->config('pin'), new SystemClock(), Connection::MODE_ONE_CERTIFICATE, $bad);
                $t->check(false, 'a connection was made for the fingerprint "' . addcslashes($bad, "\0..\37") . '"');
            } catch (LogicException) {
            }
        }
        $t->check(SendOptions::onlyThisCertificate(strtoupper($whole))->onlyCertificateSha256 === $whole, 'a whole fingerprint is accepted, in either case of letters');
        $t->check(SendOptions::checked()->onlyCertificateSha256 === '', 'the normal send names no certificate');
        foreach ([[Connection::MODE_CHECKED, $whole], [Connection::MODE_READ_CERTIFICATE, $whole], ['unchecked', ''], ['', '']] as [$mode, $pin]) {
            try {
                new Connection($t->config('pin'), new SystemClock(), $mode, $pin);
                $t->check(false, 'a connection of kind "' . $mode . '" was made');
            } catch (LogicException) {
            }
        }
        $t->check((new ReflectionClass(SendOptions::class))->getConstructor()?->isPrivate() === true, 'SendOptions can only be made through its two named ways');
    });

    $t->proof('L17a', '"This certificate only" still demands encryption: a server with no STARTTLS is refused', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('no-starttls-pinned'), build(plainSpec($now)), SendOptions::onlyThisCertificate(str_repeat('ab', 32)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_STARTTLS, SendResult::REASON_NO_ENCRYPTION);
        $t->check($t->story('no-starttls-pinned') === ['0 line EHLO client.test'], 'no sign-in and no message: ' . implode(' | ', $t->story('no-starttls-pinned')));
    });

    $t->proof('L17b', 'A server offering only the old TLS 1.1 is refused, checked or with a certificate approved', static function () use ($t, $now): void {
        [$result] = $t->send($t->transport('old-tls'), build(plainSpec($now)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_NO_ENCRYPTION, null, 'checked:');
        $t->check($result->certificate === null, 'no certificate is offered for approval');
        [$result] = $t->send($t->transport('old-tls-pinned', ['security' => SmtpConfig::TLS]), build(plainSpec($now)), SendOptions::onlyThisCertificate(str_repeat('ab', 32)));
        $t->expect($result, SendResult::TRY_LATER, SendResult::STAGE_TLS, SendResult::REASON_NO_ENCRYPTION, null, 'with a certificate approved:');
        foreach (['old-tls', 'old-tls-pinned'] as $scenario) {
            $t->check($t->count($scenario, 'tls', 'ok') === 0 && $t->count($scenario, 'auth') === 0, $scenario . ': no encryption was agreed and no sign-in was sent');
        }
    });

    $t->proof('L17c', 'The settings handed to PHP ask for TLS 1.2 or later in every kind of connection, and switch the check off only where they must', static function () use ($t): void {
        // What this reads is the ONE place the library takes its TLS versions
        // from, for the settings and for the call that starts the handshake.
        // That PHP then acts on it is shown live, by proof L17e.
        $config = $t->config('pin');
        $modern = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        $pin = str_repeat('ab', 32);
        $checked = Connection::tlsOptions($config, Connection::MODE_CHECKED, '');
        $one = Connection::tlsOptions($config, Connection::MODE_ONE_CERTIFICATE, $pin);
        $reading = Connection::tlsOptions($config, Connection::MODE_READ_CERTIFICATE, '');
        foreach (['checked' => $checked, 'one-certificate' => $one, 'read-certificate' => $reading] as $mode => $options) {
            $t->check($options['crypto_method'] === $modern, $mode . ': TLS 1.2 and 1.3 only');
            $t->check($options['peer_name'] === '127.0.0.1' && $options['capture_peer_cert'] === true, $mode . ': the name to check is the server name from the settings');
            $t->check(isset($options['ciphers']) !== true, $mode . ': the strength of encryption is left at the system\'s own setting, never lowered');
        }
        $t->check($checked['verify_peer'] === true && $checked['verify_peer_name'] === true && $checked['allow_self_signed'] === false && isset($checked['peer_fingerprint']) !== true, 'checked: the certificate and its name are both verified');
        $t->check($checked['cafile'] === $config->caFile, 'checked: the authority file handed in is used');
        $t->check($one['peer_fingerprint'] === ['sha256' => $pin], 'one-certificate: exactly the fingerprint given must match');
        $t->check(isset($reading['peer_fingerprint']) !== true && $reading['verify_peer'] === false, 'read-certificate: no check (it can write nothing; see L17d)');
    });

    $t->proof('L17d', 'A connection opened only to READ a certificate can write nothing once encryption is on', static function () use ($t): void {
        $connection = new Connection($t->config('reader-mode', ['security' => SmtpConfig::TLS]), new SystemClock(), Connection::MODE_READ_CERTIFICATE);
        $deadline = (new SystemClock())->ticks() + 20;
        $t->check($connection->open($deadline) === '' && $connection->startEncryption($deadline) === '', 'connected and encrypted, with no check');
        $t->check($connection->certificate() !== null, 'the certificate was read');
        $t->check($connection->command('EHLO client.test', $deadline)['problem'] === Connection::NOT_ALLOWED, 'a command is refused');
        $t->check($connection->secretCommand('AUTH PLAIN ' . base64_encode("\0" . TEST_USER . "\0" . TEST_PASS), $deadline, 'x')['problem'] === Connection::NOT_ALLOWED, 'a sign-in line is refused');
        $t->check($connection->sendBody("Subject: x\r\n\r\nx\r\n", $deadline) === Connection::NOT_ALLOWED, 'a message is refused');
        $connection->close(true);
        $t->check($t->story('reader-mode') === ['1 tls ok'], 'the server saw the handshake and not one byte more: ' . implode(' | ', $t->story('reader-mode')));
    });

    // -------------------------------------------------------------------------
    // X1 – X3: size and attachments, full stops, settings
    // -------------------------------------------------------------------------

    $t->proof('X1a', 'A message too big for the route or for the server is refused whole, never sent with something left out', static function () use ($t, $now): void {
        $big = build(richSpec($now));
        $transport = $t->transport('small-limit', ['maxMessageBytes' => 1000]);
        [$result] = $t->send($transport, $big);
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_MESSAGE, SendResult::REASON_TOO_BIG, null, 'over the route\'s own limit:');
        $t->check($t->count('small-limit', 'connect', '', false) === 0, 'no connection was even made');

        $transport = $t->transport('small-limit');
        [$result] = $t->send($transport, $big);
        $t->expect($result, SendResult::REFUSED, SendResult::STAGE_MESSAGE, SendResult::REASON_TOO_BIG, null, 'over the limit the server advertises:');
        $t->check(strpos($result->serverWords, '2500') !== false, 'the result names the server\'s limit');
        $t->check($t->count('small-limit', 'line', 'MAIL', false) === 0 && $t->count('small-limit', 'body', '', false) === 0, 'nothing of the message was sent');
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250, 'a small message straight after:');
        $t->check($t->count('small-limit', 'connect', '', false) === 1, 'on the same connection');
    });

    $t->proof('X1b', 'An attachment is carried whole or the message is refused: unreadable, empty and oversized files all refuse', static function () use ($t, $now): void {
        $path = $t->dir . '/attach-me.bin';
        $content = random_bytes(3000);
        file_put_contents($path, $content);
        $attachment = Message::fileAttachment($path, '', 'application/pdf');
        $t->check($attachment['name'] === 'attach-me.bin' && $attachment['content'] === $content && $attachment['type'] === 'application/pdf', 'a file is read whole and named after itself');
        $message = build(plainSpec($now, ['attachments' => [$attachment]]));
        $t->check($message->attachmentCount() === 1 && strpos($message->bytes(), substr(chunk_split(base64_encode($content), 76, "\r\n"), 0, 300)) !== false, 'the built message holds the file');
        $refused = [
            'a missing file' => static fn () => Message::fileAttachment($t->dir . '/not-there.pdf'),
            'a folder' => static fn () => Message::fileAttachment($t->dir),
            'content over 10 MB' => static fn () => build(plainSpec($now, ['attachments' => [['name' => 'big.bin', 'content' => str_repeat('x', Message::MAX_ATTACHMENT_BYTES + 1)]]])),
            'an attachment with no content key' => static fn () => new Message($now, 'a@standin.test', '', ['b@standin.test'], 's', 't', 'h', '', [['name' => 'x.txt']]),
            'an attachment that is not a list entry' => static fn () => new Message($now, 'a@standin.test', '', ['b@standin.test'], 's', 't', 'h', '', ['just a string']),
        ];
        foreach ($refused as $label => $attempt) {
            try {
                $attempt();
                $t->check(false, $label . ' was NOT refused');
            } catch (MessageRefused $refusal) {
                $t->check($refusal->field === 'attachment' && strpos($refusal->getMessage(), $t->dir) === false, $label . ': refused as "attachment", without naming the path');
            }
        }
        $exact = str_repeat('x', Message::MAX_ATTACHMENT_BYTES);
        $t->check(build(plainSpec($now, ['attachments' => [['name' => 'limit.bin', 'content' => $exact]]]))->attachmentCount() === 1, 'an attachment of exactly 10 MB is allowed');
    });

    $t->proof('X2', 'Lines beginning with a full stop, and stray line endings, cannot end a message early or smuggle in a second one', static function () use ($t): void {
        // What an attacker would put in a message body if the body were not base64.
        $payload = "Subject: full stops\r\n\r\n.starts with one\r\n..starts with two\r\n.\r\nMAIL FROM:<forged@evil.test>\r\nRCPT TO:<victim@evil.test>\r\nDATA\r\n"
            . "bare line feeds\n.\nMAIL FROM:<forged2@evil.test>\nbare carriage returns\r.\rMAIL FROM:<forged3@evil.test>\rno line ending at the end";
        $normal = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $payload) . "\r\n";

        $cases = ['' => '', 'x' => "x\r\n", '.' => "..\r\n", ".\r\n" => "..\r\n", "a\n.\nb" => "a\r\n..\r\nb\r\n", "a\r.\rb" => "a\r\n..\r\nb\r\n", "..a\r\n" => "...a\r\n", "a\r\n\r\n.b" => "a\r\n\r\n..b\r\n"];
        foreach ($cases as $in => $out) {
            $t->check(Connection::dotStuff((string) $in) === $out, 'dotStuff("' . addcslashes((string) $in, "\r\n") . '") gave "' . addcslashes(Connection::dotStuff((string) $in), "\r\n") . '"');
        }

        // On the wire, through a real conversation driven by hand.
        $clock = new SystemClock();
        $connection = new Connection($t->config('raw'), $clock);
        $by = $clock->ticks() + 30;
        $steps = [
            $connection->open($by) === '',
            $connection->readReply($by)['code'] === 220,
            $connection->command('EHLO client.test', $by)['code'] === 250,
            $connection->command('STARTTLS', $by)['code'] === 220,
            $connection->startEncryption($by) === '',
            $connection->command('EHLO client.test', $by)['code'] === 250,
            $connection->secretCommand('AUTH PLAIN ' . base64_encode("\0" . TEST_USER . "\0" . TEST_PASS), $by, 'AUTH PLAIN [removed]')['code'] === 235,
            $connection->command('MAIL FROM:<office@standin.test>', $by)['code'] === 250,
            $connection->command('RCPT TO:<reader@standin.test>', $by)['code'] === 250,
            $connection->command('DATA', $by)['code'] === 354,
            $connection->sendBody($payload, $by) === '',
            $connection->readReply($by)['code'] === 250,
        ];
        $t->check(in_array(false, $steps, true) !== true, 'the hand-driven conversation went through: step ' . ((int) array_search(false, $steps, true) + 1) . ' failed');
        $connection->close(true);
        $story = $t->story('raw');
        $t->check($t->count('raw', 'body', 'bytes=') === 1, 'the server received ONE message, not two');
        $t->check(count(preg_grep('/MAIL FROM/', $story)) === 1 && preg_grep('/forged|victim/', $story) === [], 'none of the smuggled commands was read as a command: ' . implode(' | ', array_slice($story, 5)));
        $t->check(array_slice($story, -2) === ['1 body complete=1', '1 line QUIT'], 'after the message the server saw only the goodbye');

        $folder = $t->dir . '/expect/X2';
        mkdir($folder, 0700);
        file_put_contents($folder . '/kind.txt', 'raw');
        file_put_contents($folder . '/wire-file.txt', $t->lastBodyFile('raw'));
        file_put_contents($folder . '/payload.bin', $normal);
    });

    $t->proof('X3', 'Settings that are missing, wrong or dangerous mean "not set up", and nothing is tried', static function () use ($t, $now): void {
        $good = ['host' => 'mail.standin.test', 'port' => 587, 'security' => 'starttls', 'username' => TEST_USER, 'password' => TEST_PASS];
        $make = static fn (array $over): SmtpConfig => new SmtpConfig(...array_merge($good, $over, ['password' => new Secret((string) ($over['password'] ?? $good['password']))]));
        $t->check($make([])->missing() === [], 'complete settings are complete: ' . implode(' ', $make([])->missing()));
        $t->check($make(['security' => 'tls', 'port' => 465, 'host' => '192.0.2.10'])->missing() === [], 'so are "tls" and an address in place of a name');
        $bad = [
            'no security' => ['security' => ''], 'security "none"' => ['security' => 'none'], 'security "ssl"' => ['security' => 'ssl'], 'security "STARTTLS" in capitals' => ['security' => 'STARTTLS'],
            'no server' => ['host' => ''], 'a server with a space' => ['host' => 'mail standin.test'], 'a server with a port in it' => ['host' => 'mail.standin.test:25'],
            'a server with a line break' => ['host' => "mail.standin.test\r\nx"], 'a server that is another kind of address' => ['host' => 'unix:///tmp/sock'], 'a server with a path' => ['host' => 'mail.standin.test/x'],
            'port 0' => ['port' => 0], 'port 70000' => ['port' => 70000],
            'no user name' => ['username' => ''], 'a user name with a zero byte' => ['username' => "user\0admin"],
            'no password' => ['password' => ''], 'a password with a line break' => ['password' => "pass\r\nword"],
            'an authority file that is not there' => ['caFile' => $t->dir . '/no-such-file.pem'],
            'a client name with a space' => ['clientName' => 'my host'],
            'a time limit of zero' => ['replySeconds' => 0.0], 'a negative time limit' => ['writeSeconds' => -1.0], 'a time limit of an hour' => ['messageSeconds' => 3600.0],
            'no room for any message' => ['maxMessageBytes' => 0],
        ];
        foreach ($bad as $label => $over) {
            $config = $make($over);
            $t->check($config->missing() !== [], $label . ' was accepted as complete settings');
            $result = (new SmtpTransport($config))->send(build(plainSpec($now)));
            $t->check($result->outcome === SendResult::TRY_LATER && $result->stage === SendResult::STAGE_CONFIG && $result->reason === SendResult::REASON_NOT_SET_UP, $label . ': a send comes back "not set up"');
            $t->haystack .= dumpEveryWay($result) . dumpEveryWay($config);
        }
        $t->check(findSecret(implode(' ', $make(['password' => "pass\r\nword" . TEST_PASS])->missing())) === '', 'what is said about a bad password does not repeat it');
    });

    $t->proof('X10', 'A connection that is not open answers "dropped" to every request and raises no error', static function () use ($t): void {
        $before = $t->count('raw', 'connect', '', false);
        $clock = new SystemClock();
        $connection = new Connection($t->config('raw'), $clock);
        $by = $clock->ticks() + 2;
        $t->check($connection->isOpen() !== true && $connection->isEncrypted() !== true, 'it says it is neither open nor encrypted');
        $t->check($connection->readReply($by)['problem'] === SendResult::REASON_DROPPED, 'reading a reply: dropped');
        $t->check($connection->command('NOOP', $by)['problem'] === SendResult::REASON_DROPPED, 'a command: dropped');
        $t->check($connection->startEncryption($by) === SendResult::REASON_DROPPED, 'switching encryption on: dropped');
        $t->check($connection->sendBody("Subject: x\r\n\r\nx\r\n", $by) === SendResult::REASON_DROPPED, 'a message: dropped');
        $connection->close(true);
        $connection->close(false);
        // The private helper that waits on the socket, asked directly. Every
        // caller checks for an open socket first, so nothing above can reach it
        // without one; this is the only way to hold it to its own promise.
        // Without its own check, PHP stops with an error when handed "no socket".
        $wait = new ReflectionMethod(Connection::class, 'wait');
        $t->check($wait->invoke($connection, 0.01, true) === false, 'waiting on no socket comes straight back with "nothing to read"');
        $t->check($t->count('raw', 'connect', '', false) === $before, 'and no server was contacted');
    });

    $t->proof('X12', 'Closing a route never throws, even when the clock it was given is broken, and the connection is still closed', static function () use ($t, $now): void {
        $clock = new BreakingClock('when-armed');
        $transport = $t->transport('close-clock', [], $clock);
        [$result] = $t->send($transport, build(plainSpec($now)));
        $t->expect($result, SendResult::ACCEPTED, SendResult::STAGE_BODY, SendResult::REASON_OK, 250);
        $clock->armed = true;
        try {
            $transport->close();
        } catch (Throwable $fault) {
            $t->check(false, 'close() let an exception out: ' . get_class($fault) . ': ' . $fault->getMessage());
        }
        $clock->armed = false;
        // `saw()` waits until the server has seen every connection end.
        $t->check($t->count('close-clock', 'close') === 1 && $t->count('close-clock', 'connect') === 1, 'the server saw the connection end');
        $t->check($t->count('close-clock', 'line', 'QUIT') === 0, 'without the goodbye, which could not be timed');
    });

    // -------------------------------------------------------------------------
    // L18a, L12a: last, so they see everything the proofs above loaded and kept
    // -------------------------------------------------------------------------

    $t->proof('L18a', 'The library ran with no file of any application loaded', static function () use ($t): void {
        $library = realpath(dirname(__DIR__, 3) . '/web/_core/maillib');
        $allowed = [$library, realpath(__DIR__)];
        $loaded = 0;
        foreach (get_included_files() as $file) {
            $inside = false;
            foreach ($allowed as $folder) {
                $inside = $inside === true || strncmp((string) realpath($file), $folder . DIRECTORY_SEPARATOR, strlen($folder) + 1) === 0;
            }
            $t->check($inside === true, 'a file from outside the library and its tests was loaded: ' . $file);
            $loaded += strncmp((string) realpath($file), $library . DIRECTORY_SEPARATOR, strlen($library) + 1) === 0 ? 1 : 0;
        }
        // Every one of the library's files was loaded and used, so "nothing else was needed" is said about all of it.
        $files = 1;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($library . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            $files++;
        }
        $t->check($files === 16, 'the library is its loader and 15 files under src/ (found ' . $files . ')');
        if ($t->isWholeRun() === true) {
            $t->check($loaded === $files, 'all ' . $files . ' files of the library were loaded by these proofs (' . $loaded . ' were)');
        }
        foreach (array_merge(get_declared_classes(), get_declared_interfaces()) as $class) {
            $t->check(stripos($class, 'Portal\\') !== 0, 'a class of an application is loaded: ' . $class);
        }
        foreach (array_keys(get_defined_constants(true)['user'] ?? []) as $constant) {
            $t->check(strncmp($constant, 'PORTAL_', 7) !== 0, 'a constant of an application is defined: ' . $constant);
        }
    });

    $t->proof('L12a', 'The password is nowhere in anything the library returned, threw, logged or kept, across every proof above', static function () use ($t): void {
        if ($t->isWholeRun() === true) {
            $t->check(strlen($t->haystack) > 100000, 'there was something to search (' . strlen($t->haystack) . ' bytes)');
            $t->check(substr_count($t->haystack, Secret::HIDDEN) > 50, 'the replaced sign-in lines are in it, so the search is looking in the right place');
            $t->check(strpos($t->haystack, TEST_USER) !== false, 'and so is the user name, which is not secret');
        }
        $found = findSecret($t->haystack);
        $t->check($found === '', $found);
        $t->say('searched ' . strlen($t->haystack) . ' bytes: every result, log line, dumped object, PHP warning and stack trace');
    });

    return $t->finish();
}

exit(isset($options['cases']) === true ? runCases($options) : organise($options));
