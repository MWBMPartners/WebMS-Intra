<?php
// Path: tools/maillib/tests/smtp-standin.php
/**
 * -----------------------------------------------------------------------------
 * Stand-in mail servers for the mail library's test 🎭
 * -----------------------------------------------------------------------------
 * One process that pretends to be many mail servers, each on its own port of
 * 127.0.0.1 and each with ONE scripted behaviour: accept everything, refuse
 * the sign-in, show an expired certificate, go silent after a command, stop
 * reading in the middle of a message, and so on. `run.php` starts it, sends
 * the library at it, and reads back what each server saw.
 *
 * MANY CONNECTIONS AT ONCE, IN ONE PROCESS. Each connection is served by its
 * own "fiber" (PHP 8.1 and later): a piece of code that can be paused where it
 * would otherwise wait, and picked up again when its connection has something
 * to read. So a server that is stalling on purpose never holds up the others.
 * (The first version served one connection at a time. A test that kept one
 * connection open while opening another then waited on itself until a time
 * limit fired. Starting a process per server was the other way out; fibers
 * need only the one process to start and to stop.)
 *
 * It listens on 127.0.0.1 only and never sends anything anywhere. It shares NO
 * code with the library it tests: if both were built on the same reading of the
 * SMTP rules, the same mistake in both would cancel out and prove nothing.
 *
 * HOW IT IS TOLD WHAT TO DO. `run.php` writes `scenarios.ser` (PHP's own
 * storage format: both ends are PHP, and it is private to one test run) into
 * a scratch folder and passes that folder's path. This process writes back:
 *   ports.ser          scenario name => the port it listens on
 *   standin.pid        this process's number, so it can be stopped by number
 *   log/<name>.log     one line per thing a server saw (format below)
 *   bodies/<name>-<connection>-<n>.bin   each message exactly as it arrived
 *
 * A LOG LINE is four fields separated by single spaces:
 *   <connection number> <1 if encryption was on, else 0> <kind> <the rest>
 * Kinds: connect, tls, line, auth, body, note, close. `close` is written once,
 * when the connection has ended, however it ended. The password is never written
 * to the log: a sign-in is recorded as the method, the user name and whether
 * the password matched.
 *
 * IT STOPS BY ITSELF when a file called `stop` appears in the scratch folder,
 * when the folder disappears, or after 30 minutes. That last rule is there
 * because a helper process whose test has died would otherwise wait for ever.
 *
 * 🧹 AND WHEN ITS ORGANISER DIES. `run.php` starts this with `--watch-parent`
 * and keeps the far end of this process's standard input open for as long as
 * it lives. When that end closes without a `stop` file, the organiser was
 * killed. This process then deletes the test's private keys, removes the
 * scratch folder and exits, within about a second. (Before this rule a killed
 * test left its servers listening for up to 30 minutes and its keys on disk
 * for good: seen twice during the independent check of this package.)
 * It removes the folder only if the folder holds this very process's
 * `standin.pid`, so it can never remove a folder it was merely pointed at.
 *
 * WHAT A SCENARIO MAY SAY (every key is optional):
 *   tls           'starttls' (default), 'implicit' (encrypted from the first
 *                 byte) or 'none' (never encrypts)
 *   certs         certificate names by connection number; the last repeats
 *   key           the private key file to serve them with (default 'leaf')
 *   lowSecurity   true: let this server use a key or signature that today's
 *                 OpenSSL would refuse to serve (a 1024-bit key, SHA-1)
 *   oldTls        true: offer only TLS 1.1
 *   handshake     'stall': agree to encrypt, then never do it
 *                 'cut-first': on the FIRST connection only, read the client's
 *                 opening bytes and hang up; later connections encrypt normally
 *                 'alert-first': on the first connection only, answer with a
 *                 TLS "handshake failure" alert and hang up
 *   offers        what EHLO lists before encryption; offersEncrypted: after it
 *   replies       step => what to answer. Steps: GREETING, EHLO, STARTTLS,
 *                 AUTH, MAIL, RCPT, DATA, BODY, RSET, QUIT, OTHER
 *   mail, rcpt    address => what to answer to that sender, or that recipient
 *   byConnection  connection number => more `replies`, for that connection only
 *   user, pass    the sign-in that is accepted
 *   delay         seconds to wait before every reply
 *   closeAfterMessages   close the connection after this many accepted messages
 *   maxConnections       hang up at once on any connection after this many
 *   dropAfterBytes       for '@drop-mid': how much of a message to read first
 * An answer is sent as written, or is one of these special words:
 *   @silent        say nothing and keep listening
 *   @drop          hang up at once
 *   @echo          answer "500 ..." followed by the very line that was received
 *   @drip:<text>   send <text> one byte every 0.4 seconds
 *   @stop-reading  (BODY) stop reading from the connection altogether
 *   @drop-mid      (BODY) read part of the message, then hang up
 *   @drop-after    (BODY) read the whole message, then hang up with no answer
 *   @refuse-mid:<text>  (BODY) read part of the message, send <text>, hang up
 *   @early:<text>       (BODY) read part of the message, send <text>, wait a
 *                       moment, then read the rest and accept it as usual
 *
 * @package   MWBM\Mail\Tests
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || isset($argv[1]) !== true || is_dir($argv[1]) !== true) {
    fwrite(STDERR, "usage: php smtp-standin.php <scratch folder made by run.php>\n");
    exit(2);
}

$dir = rtrim($argv[1], DIRECTORY_SEPARATOR);
$watchParent = ($argv[2] ?? '') === '--watch-parent';
if ($watchParent === true) {
    stream_set_blocking(STDIN, false);
}
$scenarios = unserialize((string) file_get_contents($dir . '/scenarios.ser'), ['allowed_classes' => false]);
if (is_array($scenarios) !== true) {
    fwrite(STDERR, "scenarios.ser could not be read\n");
    exit(2);
}

// 🎧 One listening port per scenario. Port 0 asks the system for a free one.
$listeners = [];
$ports = [];
foreach ($scenarios as $name => $spec) {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorText);
    if ($listener === false) {
        fwrite(STDERR, "could not listen for scenario $name: $errorText\n");
        exit(2);
    }
    $listeners[$name] = $listener;
    $ports[$name] = (int) substr((string) strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
}
file_put_contents($dir . '/standin.pid', (string) getmypid());
// Written under another name first and then renamed, so run.php never reads half a file.
file_put_contents($dir . '/ports.ser.tmp', serialize($ports));
rename($dir . '/ports.ser.tmp', $dir . '/ports.ser');

$connections = array_fill_keys(array_keys($scenarios), 0);
$started = time();

/**
 * The connections under way. Each entry: the fiber serving it, its session,
 * the socket it is waiting to read from (or null when it is only sleeping),
 * and the moment it should be woken whatever happens.
 *
 * @var list<array{fiber:Fiber,session:StandinSession,socket:mixed,until:float}> $waiting
 */
$waiting = [];

/** Start or wake one connection's fiber, and note what it is waiting for next. */
$run = static function (Fiber $fiber, StandinSession $session) use (&$waiting): void {
    try {
        $wants = $fiber->isStarted() === true ? $fiber->resume() : $fiber->start();
    } catch (Throwable $fault) {
        // A fault in the stand-in must be loud in its own output, and must not
        // take the other servers down with it.
        fwrite(STDERR, 'stand-in fault: ' . $fault->getMessage() . ' (line ' . $fault->getLine() . ")\n");
        $wants = null;
    }
    if ($fiber->isTerminated() === true || is_array($wants) !== true) {
        // Written last, after the hang-up: the test waits for this line before
        // it says what a server did NOT see.
        $session->finish();
        return;
    }
    $waiting[] = ['fiber' => $fiber, 'session' => $session, 'socket' => $wants['socket'], 'until' => $wants['until']];
};

while (shouldStop($dir, $started) !== true) {
    if ($watchParent === true && file_exists($dir . '/stop') !== true) {
        // Nothing is ever written to this pipe. Its end is the only news it carries.
        fread(STDIN, 16);
        if (feof(STDIN) === true) {
            orphaned($dir, $listeners);
        }
    }
    $read = array_values($listeners);
    $nearest = microtime(true) + 0.25;
    foreach ($waiting as $entry) {
        if (is_resource($entry['socket']) === true) {
            $read[] = $entry['socket'];
        }
        $nearest = min($nearest, $entry['until']);
    }
    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 0, (int) (max(0.0, $nearest - microtime(true)) * 1000000));

    // New connections.
    foreach ($listeners as $name => $listener) {
        if (in_array($listener, $read, true) !== true) {
            continue;
        }
        $connection = @stream_socket_accept($listener, 0);
        if ($connection === false) {
            continue;
        }
        stream_set_blocking($connection, false);
        $connections[$name]++;
        $session = new StandinSession($dir, (string) $name, $scenarios[$name], $connections[$name], $connection, $started);
        $run(new Fiber($session->serve(...)), $session);
    }

    // Connections whose socket has something to read, or whose wait is over.
    $now = microtime(true);
    $due = [];
    $stillWaiting = [];
    foreach ($waiting as $entry) {
        $ready = (is_resource($entry['socket']) === true && in_array($entry['socket'], $read, true) === true) || $entry['until'] <= $now;
        $ready === true ? $due[] = $entry : $stillWaiting[] = $entry;
    }
    $waiting = $stillWaiting;
    foreach ($due as $entry) {
        $run($entry['fiber'], $entry['session']);
    }
}
foreach ($waiting as $entry) {
    $entry['session']->finish();
}
exit(0);

/**
 * The organiser has died. Stop listening, delete the private keys, remove the
 * scratch folder (only if it is provably this process's own) and exit.
 *
 * @param array<string,resource> $listeners
 */
function orphaned(string $dir, array $listeners): never
{
    foreach ($listeners as $listener) {
        fclose($listener);
    }
    $keys = glob($dir . '/certs/*.key');
    foreach (is_array($keys) === true ? $keys : [] as $key) {
        @unlink($key);
    }
    if (trim((string) @file_get_contents($dir . '/standin.pid')) === (string) getmypid()) {
        removeScratch($dir);
    }
    exit(3);
}

/** Remove a folder and everything in it. Links are removed, never followed. */
function removeScratch(string $folder): void
{
    $items = scandir($folder);
    foreach (is_array($items) === true ? $items : [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $folder . DIRECTORY_SEPARATOR . $item;
        is_dir($path) === true && is_link($path) !== true ? removeScratch($path) : @unlink($path);
    }
    @rmdir($folder);
}

/** True when the test has asked the stand-in to stop, has gone away, or has been running too long. */
function shouldStop(string $dir, int $started): bool
{
    clearstatcache();
    return is_dir($dir) !== true || file_exists($dir . '/stop') === true || time() - $started > 1800;
}

/**
 * One connection to one stand-in server, from the first byte to the hang-up.
 */
final class StandinSession
{
    private bool $encrypted = false;
    private string $buffer = '';
    private int $messages = 0;

    /**
     * @param array<string,mixed> $spec   The scenario.
     * @param resource            $socket The accepted connection, already non-blocking.
     */
    public function __construct(
        private readonly string $dir,
        private readonly string $name,
        private readonly array $spec,
        private readonly int $number,
        private $socket,
        private readonly int $started,
    ) {
    }

    /** Hang up and write the `close` line. Called once, when the connection's fiber has ended. */
    public function finish(): void
    {
        if (is_resource($this->socket) === true) {
            @fclose($this->socket);
        }
        $this->log('close', '');
    }

    /**
     * Step aside so other connections can be served, until this connection has
     * something to read (when `$forReading` is true) or the time is up.
     */
    private function pause(float $seconds, bool $forReading): void
    {
        Fiber::suspend(['socket' => $forReading === true ? $this->socket : null, 'until' => microtime(true) + $seconds]);
    }

    /** Write all of some bytes, stepping aside if the client is slow to take them. False if it has gone. */
    private function put(string $bytes): bool
    {
        $until = microtime(true) + 20;
        while ($bytes !== '') {
            $taken = @fwrite($this->socket, $bytes);
            // A write to a client that has already hung up an encrypted
            // connection reports "0 bytes taken", not a failure, so the
            // hang-up has to be asked about directly. (Without this the
            // stand-in kept offering a greeting to a closed connection for
            // the full 20 seconds.)
            if ($taken === false || microtime(true) > $until || ($taken === 0 && feof($this->socket) === true)) {
                return false;
            }
            $bytes = (string) substr($bytes, $taken);
            if ($bytes !== '') {
                $this->pause(0.05, false);
            }
        }
        return true;
    }

    public function serve(): void
    {
        $this->log('connect', '');
        if ($this->number > (int) ($this->spec['maxConnections'] ?? PHP_INT_MAX)) {
            $this->log('note', 'hung up at once: over maxConnections');
            return;
        }
        $mode = (string) ($this->spec['tls'] ?? 'starttls');
        if ($mode === 'implicit' && $this->encrypt() !== true) {
            return;
        }
        if ($this->answer('GREETING', '220 standin.test ESMTP ready', '') !== true) {
            return;
        }
        while (true) {
            $line = $this->readLine();
            if ($line === null) {
                $this->log('note', 'the client hung up or went quiet; ' . strlen($this->buffer) . ' bytes left unread');
                return;
            }
            $verb = strtoupper((string) strtok($line, ' '));
            if ($verb === 'AUTH') {
                if ($this->signIn($line) !== true) {
                    return;
                }
                continue;
            }
            $this->log('line', $line);
            if ($verb === 'EHLO') {
                $offers = $this->encrypted === true
                    ? ($this->spec['offersEncrypted'] ?? ['AUTH PLAIN LOGIN', 'SIZE 20971520'])
                    : ($this->spec['offers'] ?? ($mode === 'starttls' ? ['STARTTLS'] : ['AUTH PLAIN LOGIN']));
                $lines = array_merge(['standin.test'], $offers);
                $last = array_pop($lines);
                $default = '';
                foreach ($lines as $offer) {
                    $default .= '250-' . $offer . "\r\n";
                }
                $continue = $this->answer('EHLO', $default . '250 ' . $last, $line);
            } elseif ($verb === 'STARTTLS') {
                $continue = $this->answer('STARTTLS', '220 2.0.0 ready to start encryption', $line);
                // Encrypt only if this server really said "220, go ahead" and
                // nothing more. (A scenario that adds text after the 220 is
                // testing whether the client notices the extra text.)
                if ($continue === true && strncmp((string) $this->reply('STARTTLS', '220'), '220', 3) === 0 && $mode === 'starttls') {
                    $continue = $this->encrypt();
                }
            } elseif ($verb === 'MAIL' || $verb === 'RCPT') {
                // A scenario may answer one particular address differently.
                $address = preg_match('/<([^>]*)>/', $line, $found) === 1 ? $found[1] : '';
                $default = $verb === 'MAIL' ? '250 2.1.0 sender ok' : '250 2.1.5 recipient ok';
                $continue = $this->send((string) ($this->spec[strtolower($verb)][$address] ?? $this->reply($verb, $default)), $line);
            } elseif ($verb === 'DATA') {
                $continue = $this->answer('DATA', '354 end the message with a line holding only a full stop', $line);
                if ($continue === true && strncmp((string) $this->reply('DATA', '354'), '354', 3) === 0) {
                    $continue = $this->takeMessage();
                }
            } elseif ($verb === 'RSET') {
                $continue = $this->answer('RSET', '250 2.0.0 cleared', $line);
            } elseif ($verb === 'QUIT') {
                $this->answer('QUIT', '221 2.0.0 bye', $line);
                $this->log('note', 'quit');
                return;
            } else {
                $continue = $this->answer('OTHER', '500 5.5.1 command not recognised', $line);
            }
            if ($continue !== true) {
                return;
            }
        }
    }

    // -------------------------------------------------------------------------
    // What a scenario says to answer
    // -------------------------------------------------------------------------

    private function reply(string $step, string $default): string
    {
        return (string) ($this->spec['byConnection'][$this->number]['replies'][$step] ?? $this->spec['replies'][$step] ?? $default);
    }

    /** Send this step's scripted answer. False when the connection is over. */
    private function answer(string $step, string $default, string $received): bool
    {
        return $this->send($this->reply($step, $default), $received);
    }

    /** Send one answer, or act out one of the special words. False when the connection is over. */
    private function send(string $reply, string $received): bool
    {
        if (isset($this->spec['delay']) === true) {
            $this->pause((float) $this->spec['delay'], false);
        }
        if ($reply === '@silent') {
            return true;
        }
        if ($reply === '@drop') {
            $this->log('note', 'hung up on purpose');
            return false;
        }
        if ($reply === '@echo') {
            $reply = '500 5.5.1 not understood: ' . $received;
        }
        if (strncmp($reply, '@drip:', 6) === 0) {
            foreach (str_split(substr($reply, 6) . "\r\n") as $byte) {
                if ($this->put($byte) !== true || $this->gone() === true) {
                    return false;
                }
                $this->pause(0.4, false);
            }
            return true;
        }
        return $this->put($reply . "\r\n");
    }

    // -------------------------------------------------------------------------
    // Encryption
    // -------------------------------------------------------------------------

    private function encrypt(): bool
    {
        $how = (string) ($this->spec['handshake'] ?? '');
        if ($how === 'stall') {
            // Agree to encrypt and then do nothing, until the client gives up.
            $this->log('tls', 'stalling on purpose');
            $this->waitForHangUp(false);
            return false;
        }
        if (($how === 'cut-first' || $how === 'alert-first') && $this->number === 1) {
            // A handshake that fails for a reason that is NOT the certificate:
            // what a fault on the line, or somebody in the middle, looks like.
            $this->readMore();
            if ($how === 'alert-first') {
                // One TLS record: alert, fatal, "handshake failure".
                $this->put("\x15\x03\x03\x00\x02\x02\x28");
                $this->pause(0.2, false);
            }
            $this->log('tls', 'cut on purpose (' . $how . ')');
            return false;
        }
        $certs = (array) ($this->spec['certs'] ?? ['good']);
        $cert = (string) ($certs[min($this->number, count($certs)) - 1]);
        stream_context_set_option($this->socket, 'ssl', 'local_cert', $this->dir . '/certs/' . $cert . '.pem');
        stream_context_set_option($this->socket, 'ssl', 'local_pk', $this->dir . '/certs/' . ($this->spec['key'] ?? 'leaf') . '.key');
        if (($this->spec['lowSecurity'] ?? false) === true) {
            stream_context_set_option($this->socket, 'ssl', 'ciphers', 'DEFAULT@SECLEVEL=0');
        }
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
        if (($this->spec['oldTls'] ?? false) === true) {
            // TLS 1.1 only. OpenSSL 3 will not even offer it unless its
            // "security level" is lowered, hence the cipher setting.
            stream_context_set_option($this->socket, 'ssl', 'ciphers', 'DEFAULT@SECLEVEL=0');
            $method = STREAM_CRYPTO_METHOD_TLSv1_1_SERVER;
        }
        $why = '';
        $until = microtime(true) + 20;
        while (true) {
            set_error_handler(static function (int $level, string $message) use (&$why): bool {
                $why .= $message . ' ';
                return true;
            });
            // On a non-blocking socket: true (done), false (failed) or 0 (not yet).
            $done = stream_socket_enable_crypto($this->socket, true, $method);
            restore_error_handler();
            if ($done !== 0 || microtime(true) > $until || feof($this->socket) === true) {
                break;
            }
            $this->pause(0.2, true);
        }
        if ($done !== true) {
            $this->log('tls', 'failed ' . trim(str_replace(["\r", "\n"], ' ', $why)));
            return false;
        }
        $this->encrypted = true;
        $this->log('tls', 'ok ' . (stream_get_meta_data($this->socket)['crypto']['protocol'] ?? '?') . ' certificate=' . $cert);
        return true;
    }

    // -------------------------------------------------------------------------
    // Signing in
    // -------------------------------------------------------------------------

    /** Handle AUTH PLAIN and AUTH LOGIN. False when the connection is over. */
    private function signIn(string $line): bool
    {
        $parts = explode(' ', $line);
        $method = strtoupper($parts[1] ?? '');
        $user = '';
        $pass = '';
        $last = $line;
        if ($method === 'PLAIN') {
            $fields = explode("\0", (string) base64_decode($parts[2] ?? '', true));
            $user = $fields[1] ?? '';
            $pass = $fields[2] ?? '';
        } elseif ($method === 'LOGIN') {
            // "Username:" and "Password:" in base64, as real servers ask.
            if ($this->put("334 VXNlcm5hbWU6\r\n") !== true || ($userLine = $this->readLine()) === null) {
                return false;
            }
            $user = (string) base64_decode($userLine, true);
            if ($this->put("334 UGFzc3dvcmQ6\r\n") !== true || ($last = $this->readLine()) === null) {
                return false;
            }
            $pass = (string) base64_decode($last, true);
        }
        $matched = $method !== '' && $user === ($this->spec['user'] ?? null) && $pass === ($this->spec['pass'] ?? null);
        $this->log('auth', $method . ' user=' . $user . ' matched=' . ($matched === true ? '1' : '0'));
        return $this->answer(
            'AUTH',
            $matched === true ? '235 2.7.0 signed in' : '535 5.7.8 the user name or password is wrong',
            $last,
        );
    }

    // -------------------------------------------------------------------------
    // A message
    // -------------------------------------------------------------------------

    /** Read one message after "354", keep it exactly as it arrived, and answer. */
    private function takeMessage(): bool
    {
        $how = $this->reply('BODY', '');
        if ($how === '@stop-reading') {
            // Stop reading altogether: the client's writes back up until it
            // gives up. The connection is held, unread, for two minutes or
            // until the test ends, whichever comes first.
            $this->log('body', 'stopped reading on purpose');
            $this->waitForHangUp(true);
            return false;
        }
        $refuseMid = strncmp($how, '@refuse-mid:', 12) === 0;
        $limit = $how === '@drop-mid' || $refuseMid === true ? (int) ($this->spec['dropAfterBytes'] ?? 200000) : PHP_INT_MAX;
        $data = $this->buffer;
        $this->buffer = '';
        // The one true end of a message: CR LF, a full stop, CR LF. Nothing
        // looser is accepted here, so a client that let a bare line feed
        // through would be caught by the checks made on the saved bytes.
        $complete = false;
        $early = strncmp($how, '@early:', 7) === 0;
        $spoken = false;
        while (true) {
            $end = strncmp($data, ".\r\n", 3) === 0 ? 0 : strpos($data, "\r\n.\r\n");
            if ($end !== false) {
                $length = $end === 0 ? 3 : $end + 5;
                $this->buffer = (string) substr($data, $length);
                $data = substr($data, 0, $length);
                $complete = true;
                break;
            }
            if (strlen($data) >= $limit) {
                break;
            }
            if ($early === true && $spoken !== true && strlen($data) >= 200000) {
                // Speak while the message is still arriving, and stop reading
                // for a moment so that the client's writing backs up and it
                // has to notice.
                $spoken = true;
                $this->put(substr($how, 7) . "\r\n");
                $this->pause(1.0, false);
                $this->log('note', 'spoke part way through the message');
            }
            $more = $this->readMore();
            if ($more === null) {
                break;
            }
            $data .= $more;
        }
        $this->messages++;
        $file = 'bodies/' . $this->name . '-' . $this->number . '-' . $this->messages . '.bin';
        file_put_contents($this->dir . '/' . $file, $data);
        $this->log('body', 'bytes=' . strlen($data) . ' complete=' . ($complete === true ? '1' : '0') . ' file=' . $file);
        if ($refuseMid === true && $complete !== true) {
            // Say no part way through, give the words time to arrive, hang up.
            $this->put(substr($how, 12) . "\r\n");
            $this->pause(0.5, false);
            $this->log('note', 'refused part way through the message, then hung up');
            return false;
        }
        if ($complete !== true || $how === '@drop-mid' || $how === '@drop-after') {
            $this->log('note', $complete === true ? 'hung up after the message, with no answer' : 'hung up part way through the message');
            return false;
        }
        $answered = $this->send($how === '' || $early === true ? '250 2.0.0 queued as STANDIN-' . $this->number . '-' . $this->messages : $how, '');
        if ($answered === true && $this->messages >= (int) ($this->spec['closeAfterMessages'] ?? PHP_INT_MAX)) {
            $this->log('note', 'hung up after ' . $this->messages . ' message(s), as scripted');
            return false;
        }
        return $answered;
    }

    // -------------------------------------------------------------------------
    // Reading, waiting and logging
    // -------------------------------------------------------------------------

    /** One line from the client, without its line ending. Null when it hung up or went quiet for 90 seconds. */
    private function readLine(): ?string
    {
        while (true) {
            $end = strpos($this->buffer, "\n");
            if ($end !== false) {
                $line = substr($this->buffer, 0, $end);
                $this->buffer = (string) substr($this->buffer, $end + 1);
                return rtrim($line, "\r");
            }
            $more = $this->readMore();
            if ($more === null) {
                return null;
            }
            $this->buffer .= $more;
        }
    }

    /** More bytes from the client. Null when it hung up, went quiet for 90 seconds, or the test is over. */
    private function readMore(): ?string
    {
        $until = microtime(true) + 90;
        while (microtime(true) < $until) {
            // Read BEFORE waiting: with encryption on, PHP may already hold
            // bytes that the socket no longer shows as waiting.
            $chunk = @fread($this->socket, 65536);
            if (is_string($chunk) === true && $chunk !== '') {
                return $chunk;
            }
            if (feof($this->socket) === true || shouldStop($this->dir, $this->started) === true) {
                return null;
            }
            $this->pause(0.5, true);
        }
        return null;
    }

    /** True when the client has hung up. (Anything it sent in the meantime is thrown away.) */
    private function gone(): bool
    {
        $peek = @fread($this->socket, 1024);
        return ($peek === '' || $peek === false) && feof($this->socket) === true;
    }

    /**
     * Hold the connection open while doing nothing.
     *
     * @param bool $withoutReading True to leave whatever the client writes unread (so its writes back up).
     *                             False to read and throw away, which also shows the moment it hangs up.
     */
    private function waitForHangUp(bool $withoutReading): void
    {
        $until = microtime(true) + 120;
        while (microtime(true) < $until && shouldStop($this->dir, $this->started) !== true) {
            if ($withoutReading !== true) {
                if ($this->readMore() === null) {
                    break;
                }
                continue;
            }
            $this->pause(0.25, false);
        }
        $this->log('note', 'released after a stall');
    }

    public function log(string $kind, string $rest): void
    {
        $safe = addcslashes($rest, "\0..\37\177");
        file_put_contents(
            $this->dir . '/log/' . $this->name . '.log',
            $this->number . ' ' . ($this->encrypted === true ? '1' : '0') . ' ' . $kind . ' ' . $safe . "\n",
            FILE_APPEND,
        );
    }
}
