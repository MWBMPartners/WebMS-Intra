<?php
// Path: tools/https-standin-client.php
/**
 * -----------------------------------------------------------------------------
 * How a self-test starts, steers and stops `tools/https-standin.php` 🎭🔒
 * -----------------------------------------------------------------------------
 * The ONE copy of the steps, so the tests that use the stand-in (#1006 in
 * `mail-queue-selftest.php`, #1017 in `erasure-pages-selftest.php`) cannot
 * drift apart on how it is started or what the portal's server is given.
 * Read the stand-in's own header for what it is and what it cannot prove.
 *
 * THE TWO THINGS A TEST HANDS THE PORTAL'S SERVER, from httpsStandinServerArgs()
 * and httpsStandinServerEnv():
 *   - `-d openssl.cafile=<bundle>` AND `-d curl.cainfo=<bundle>`. PHP's curl
 *     takes `openssl.cafile` FIRST and reads `curl.cainfo` only when that is
 *     empty - measured on 12 November 2026 on PHP 8.5.12 with Homebrew's
 *     OpenSSL 4.1, where `openssl.cafile` is set to the system's list, so
 *     `curl.cainfo` alone was IGNORED and every call failed "unable to get
 *     local issuer certificate". Both are given, so it works whichever is
 *     set on the machine running the test.
 *   - `https_proxy` (and `HTTPS_PROXY`) pointing at the stand-in, with
 *     `no_proxy` naming 127.0.0.1 and localhost so the test's own requests
 *     and the portal's calls to its own address are never sent to it.
 *
 * `<bundle>` holds the stand-in's authority and any the test already trusted
 * (`$alsoTrust`), because `openssl.cafile` is ONE file: a test that already
 * pointed it at another authority (the stand-in mail servers') keeps that.
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

/**
 * Start the stand-in in `$dir` (made here; it must not exist yet) for the
 * given server names. Refuses through `$refuse` (the test's own) when it
 * cannot start, never quietly.
 *
 * @param list<string> $names     The names it answers for.
 * @param list<string> $alsoTrust Authority files the server must go on trusting.
 *
 * @return array{proc: mixed, pipe: mixed, dir: string, port: int, bundle: string}
 */
function httpsStandinStart(string $dir, array $names, array $alsoTrust, callable $refuse): array
{
    if (file_exists($dir) === true || @mkdir($dir, 0700) === false) {
        $refuse("the HTTPS stand-in's folder '{$dir}' could not be made new");
    }
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'answers.json', '{}');
    // A LIST, no shell; `--watch-parent` with a pipe nobody writes to, so the
    // stand-in stops when the test does, however the test ends.
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'https-standin.php', $dir, implode(',', $names), '--watch-parent'],
        [0 => ['pipe', 'r'], 1 => ['file', $dir . DIRECTORY_SEPARATOR . 'standin.out', 'w'], 2 => ['file', $dir . DIRECTORY_SEPARATOR . 'standin.err', 'w']],
        $pipes
    );
    if (is_resource($proc) === false) {
        $refuse('the HTTPS stand-in could not be started');
    }
    $until = microtime(true) + 20;
    while (is_file($dir . DIRECTORY_SEPARATOR . 'port') === false && microtime(true) < $until) {
        usleep(50000);
    }
    $port = (int) @file_get_contents($dir . DIRECTORY_SEPARATOR . 'port');
    if ($port < 1) {
        $refuse('the HTTPS stand-in never said which port it listens on: ' . trim((string) @file_get_contents($dir . DIRECTORY_SEPARATOR . 'standin.err')));
    }
    $bundle = $dir . DIRECTORY_SEPARATOR . 'trusted.pem';
    $text   = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'ca.pem');
    foreach ($alsoTrust as $file) {
        $text .= "\n" . (string) file_get_contents($file);
    }
    file_put_contents($bundle, $text);

    return ['proc' => $proc, 'pipe' => $pipes[0] ?? null, 'dir' => $dir, 'port' => $port, 'bundle' => $bundle];
}

/** The `-d` settings the portal's server is started with. */
function httpsStandinServerArgs(array $standin): array
{
    return ['-d', 'openssl.cafile=' . $standin['bundle'], '-d', 'curl.cainfo=' . $standin['bundle']];
}

/** The portal's server's environment, with the stand-in as its HTTPS proxy. */
function httpsStandinServerEnv(array $standin, array $env): array
{
    $proxy = 'http://127.0.0.1:' . (int) $standin['port'];
    $env['https_proxy'] = $proxy;
    $env['HTTPS_PROXY'] = $proxy;
    $env['no_proxy']    = '127.0.0.1,localhost';
    $env['NO_PROXY']    = '127.0.0.1,localhost';

    return $env;
}

/**
 * What each service says from now on: { name: [ {path, status, json|body}, ... ] }.
 * Written under another name and renamed, so the stand-in never reads half a file.
 */
function httpsStandinAnswers(array $standin, array $answers): void
{
    $file = $standin['dir'] . DIRECTORY_SEPARATOR . 'answers.json';
    file_put_contents($file . '.tmp', (string) json_encode($answers, JSON_UNESCAPED_SLASHES));
    rename($file . '.tmp', $file);
}

/**
 * Every request the stand-in has answered since the test last asked, and
 * forget them. Each: host, method, path, status, bytes (never a body).
 *
 * @return list<array{host: string, method: string, path: string, status: int, bytes: int}>
 */
function httpsStandinRequests(array $standin): array
{
    $file = $standin['dir'] . DIRECTORY_SEPARATOR . 'requests.jsonl';
    $text = (string) @file_get_contents($file);
    @file_put_contents($file, '');
    $seen = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $one = json_decode($line, true);
        if (is_array($one) === true) {
            $seen[] = $one;
        }
    }

    return $seen;
}

/** Stop it: the file it watches for, then its pipe, then its process by handle. Never by a name. */
function httpsStandinStop(array &$standin): void
{
    if (($standin['dir'] ?? '') !== '' && is_dir($standin['dir']) === true) {
        @touch($standin['dir'] . DIRECTORY_SEPARATOR . 'stop');
    }
    if (is_resource($standin['pipe'] ?? null) === true) {
        fclose($standin['pipe']);
    }
    $proc = $standin['proc'] ?? null;
    if (is_resource($proc) === true) {
        $until = microtime(true) + 5;
        while (proc_get_status($proc)['running'] === true && microtime(true) < $until) {
            usleep(50000);
        }
        if (proc_get_status($proc)['running'] === true) {
            proc_terminate($proc, 9);
        }
        proc_close($proc);
    }
    $standin['proc'] = null;
    $standin['pipe'] = null;
}
