<?php
// Path: web/_core/maillib/src/Smtp/Connection.php
/**
 * -----------------------------------------------------------------------------
 * Connection — one line to a mail server, with every wait on a clock 🔌
 * -----------------------------------------------------------------------------
 * The lowest layer of the SMTP route: open the line, switch encryption on,
 * write a command, read a reply, write a message, close. It knows the grammar
 * of a reply but nothing about the order of a conversation: `SmtpTransport`
 * decides that.
 *
 * ⏱️ WHY NOTHING HERE EVER WAITS WITHOUT A CLOCK. The socket is put in
 * "non-blocking" mode the moment it is open, which means no read or write can
 * hold the program: each returns at once with whatever could be done. This
 * class then waits in short slices and checks the steady timer between them.
 * So a limit is a limit on the WHOLE step, measured by us.
 *   - Tried and rejected: PHP's own stream timeout with ordinary reads. It
 *     restarts for every piece that arrives, so a server sending one byte
 *     every few seconds could hold a reply open for ever.
 *   - Tried and rejected: an ordinary write. A trial against a server that
 *     had stopped reading showed it ignores its timeout altogether: it hung
 *     for 40 seconds, until the server gave up. Writing in pieces against the
 *     clock stopped on time, in the clear and (proved for this file) over TLS.
 *
 * 🔒 THREE WAYS A CONNECTION CAN BE OPENED, and only the first two can send:
 *   checked           The certificate is checked in full by PHP and OpenSSL,
 *                     and then its NAME is checked again by this library's
 *                     own stricter rule (`CertificateFacts::nameMatches()`).
 *                     PHP 8.5 accepts some names PHP 8.4 refuses, so PHP's
 *                     answer alone would differ between the two versions.
 *   one-certificate   The general check is replaced by "exactly this
 *                     certificate": its SHA-256 fingerprint must match, tested
 *                     by PHP and then again here.
 *   read-certificate  No check at all, for `CertificateReader` to find out WHY
 *                     a certificate failed. A connection in this mode REFUSES
 *                     EVERY WRITE once encryption is on (see `write()`), so it
 *                     can read a certificate and can do nothing else. That one
 *                     rule is what makes it safe to have an unchecked mode in
 *                     the code at all.
 * In every mode encryption is TLS 1.2 or later; there is no mode without it.
 *
 * 🪪 HOW A FAILED HANDSHAKE IS SORTED (see `startEncryption()`). The
 * certificate is blamed ONLY when PHP says in so many words that it refused
 * the certificate, or when this class refused it by its own rule. A handshake
 * that was cut off, answered with an alert, or failed with words this class
 * does not know is "encryption failed, try later". That direction is chosen on
 * purpose: an application may hold all mail and alert an administrator on a
 * certificate fault, so a passing fault on the line must never look like one.
 *
 * WHAT IT CANNOT DO. The wait to look up the server's name (DNS) is PHP's own
 * and is not on this clock. And a time limit is checked between slices of a
 * quarter of a second, so a step can run that much past its limit.
 *
 * @package   MWBM\Mail\Smtp
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * @link      https://www.rfc-editor.org/rfc/rfc5321 (SMTP)
 * @link      https://www.rfc-editor.org/rfc/rfc3207 (STARTTLS)
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail\Smtp;

use MWBM\Mail\CertificateFacts;
use MWBM\Mail\Clock;
use MWBM\Mail\SendOptions;
use MWBM\Mail\SendResult;

final class Connection
{
    public const MODE_CHECKED = 'checked';
    public const MODE_ONE_CERTIFICATE = 'one-certificate';
    public const MODE_READ_CERTIFICATE = 'read-certificate';

    /** A write or a sign-in line was refused by this class's own rules. Nothing was sent. */
    public const NOT_ALLOWED = 'not-allowed';

    /**
     * From `sendBody()`: the whole message WAS written, but the server said
     * something while it was still going out. Its verdict cannot be told from
     * that early text, so nobody knows whether the message was taken.
     */
    public const SPOKE_DURING_MESSAGE = 'spoke-during-message';

    /** The longest single wait before the clock is looked at again, in seconds. */
    private const SLICE = 0.25;

    /** How much is offered to the socket in one write. */
    private const PIECE = 16384;

    /**
     * A reply line longer than this, or a reply of more lines than this, is
     * not a reply. `takeLine()` holds every line to the first limit, whether
     * it arrived in pieces or all at once. (It used to test only a line still
     * arriving, so a 6000-byte line that came whole was read as a reply.)
     */
    private const MAX_LINE_BYTES = 4096;
    private const MAX_REPLY_LINES = 100;

    /**
     * TLS 1.2 and 1.3 only. Older versions are broken. This ONE value is used
     * both in the settings and in the call that starts the handshake.
     */
    private const TLS_VERSIONS = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /**
     * 🪪 PHP's own words for "I refused the server's certificate", on PHP 8.4
     * and 8.5 (read out of both programs on 12 October 2026):
     *   - "certificate verify failed"            OpenSSL, during the handshake: expired, not yet
     *                                            valid, unknown issuer, signed by itself, weak
     *                                            key, weak signature, not for a server
     *   - "Peer certificate CN=... did not match expected CN=..."            the name (8.4, 8.5)
     *   - "Peer certificate subjectAltName did not match expected name ..."  the name (8.4 only)
     *   - "Peer certificate CN=... is malformed", "Unable to locate peer certificate CN",
     *     "Could not get peer certificate", "Could not verify peer: code:..."
     *   - "peer_fingerprint match failure"       not the approved certificate
     * Every one of them holds one of the four pieces below.
     *
     * IF A FUTURE PHP WORDS A REFUSAL DIFFERENTLY, it will not be recognised
     * here and a refused certificate will be reported as "encryption failed,
     * try later" until this list is brought up to date. That is the safe
     * direction (nothing is sent either way), and the library's own test
     * fails on such a PHP, because it demands "certificate" for each kind of
     * bad certificate. What must never happen is the other mistake: a line
     * fault reported as a bad certificate.
     */
    private const PHP_BLAMES_THE_CERTIFICATE = '/certificate verify failed|peer certificate|could not verify peer|peer_fingerprint match failure/i';

    /** OpenSSL's words for "the two sides share no version of TLS" (an old server, or a port that speaks no TLS). */
    private const NO_SHARED_VERSION = '/wrong version number|protocol version|unsupported protocol|no protocols available|version too low/i';

    /** @var resource|null The open socket. */
    private $stream = null;

    /** What has arrived from the server and not yet been read as a reply. */
    private string $buffer = '';

    private bool $encrypted = false;
    private ?CertificateFacts $certificate = null;

    /**
     * A certificate this class itself refused AFTER the handshake (the wrong
     * name, or not the approved one), kept so the caller can say why without
     * connecting a second time.
     */
    private ?CertificateFacts $refused = null;

    /** PHP's own warnings from the last open or handshake: the only place the real cause is said. */
    private string $phpWords = '';

    /** @var list<string> The conversation so far, with sign-in lines replaced and no message body. */
    private array $transcript = [];

    /**
     * @param string $mode One of the three `MODE_` values.
     * @param string $pin  For `one-certificate`: the SHA-256 fingerprint to accept. Empty otherwise.
     */
    public function __construct(
        private readonly SmtpConfig $config,
        private readonly Clock $clock,
        private readonly string $mode = self::MODE_CHECKED,
        private readonly string $pin = '',
    ) {
        $valid = match ($mode) {
            self::MODE_CHECKED, self::MODE_READ_CERTIFICATE => $pin === '',
            // 🔒 Only a whole fingerprint. An empty or partial one must never
            // reach PHP, where "no fingerprint" would mean "no check".
            self::MODE_ONE_CERTIFICATE => SendOptions::isFingerprint($pin),
            default => false,
        };
        if ($valid !== true) {
            throw new \LogicException('A connection was asked for in a way that does not exist.');
        }
    }

    /**
     * The encryption settings handed to PHP. Kept as one small function with
     * no side effects so that a test can read exactly what each mode asks for.
     *
     * @return array<string,mixed>
     */
    public static function tlsOptions(SmtpConfig $config, string $mode, string $pin): array
    {
        $options = [
            // TLS 1.2 and 1.3 only, in every mode.
            // ⚠️ PHP IGNORES THIS ENTRY for a handshake started the way this
            // class starts one: it obeys the method given in the call to
            // stream_socket_enable_crypto() (tried on PHP 8.4 and 8.5 against
            // a TLS 1.1 server). So `startEncryption()` takes the method for
            // that call FROM this entry. A test that reads this entry is then
            // reading the value really used.
            'crypto_method' => self::TLS_VERSIONS,
            // The name the certificate must be for: the server name in the settings.
            'peer_name' => $config->host,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'disable_compression' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ];
        if ($mode === self::MODE_CHECKED) {
            if ($config->caFile !== '') {
                $options['cafile'] = $config->caFile;
            }
            return $options;
        }
        $options['verify_peer'] = false;
        $options['verify_peer_name'] = false;
        if ($mode === self::MODE_ONE_CERTIFICATE) {
            $options['peer_fingerprint'] = ['sha256' => $pin];
        }
        return $options;
    }

    // -------------------------------------------------------------------------
    // 🔌 Opening and encrypting
    // -------------------------------------------------------------------------

    /**
     * Reach the server. Nothing is said yet.
     *
     * @param float $deadline The reading of the steady timer by which this must be done.
     *
     * @return string Empty when open; otherwise a `SendResult::REASON_` name.
     */
    public function open(float $deadline): string
    {
        $this->phpWords = '';
        if ($this->config->missing() !== []) {
            return SendResult::REASON_NOT_SET_UP;
        }
        $limit = min($this->config->connectSeconds, $deadline - $this->clock->ticks());
        if ($limit <= 0.0) {
            return SendResult::REASON_TIMEOUT;
        }
        $context = stream_context_create(['ssl' => self::tlsOptions($this->config, $this->mode, $this->pin)]);
        $address = 'tcp://' . $this->config->host . ':' . $this->config->port;
        $started = $this->clock->ticks();
        $stream = $this->quietly(static function () use ($address, $limit, $context) {
            return stream_socket_client($address, $errorNumber, $errorText, $limit, STREAM_CLIENT_CONNECT, $context);
        });
        if (is_resource($stream) !== true) {
            return $this->clock->ticks() - $started >= $limit
                ? SendResult::REASON_TIMEOUT
                : SendResult::REASON_CANNOT_CONNECT;
        }
        // ⏱️ From here on no read or write can hold the program. See the file header.
        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->transcript[] = 'Connected to ' . $this->config->host . ' on port ' . $this->config->port . '.';
        return '';
    }

    /**
     * Switch encryption on and apply this connection's certificate rule.
     *
     * @return string Empty when encryption is on and the certificate passed. Otherwise:
     *                `timeout`, `dropped` or `injected-text`;
     *                `certificate` when PHP said it refused the certificate, or this class refused
     *                it by its own rule (then `refusedCertificate()` may hold its facts);
     *                `no-encryption-offered` when the two sides share no version of TLS;
     *                `encryption-failed` for every other failed handshake: cut off, answered with
     *                an alert, or failed with words this class does not know. NOT the certificate.
     */
    public function startEncryption(float $deadline): string
    {
        $this->phpWords = '';
        $this->refused = null;
        $stream = $this->stream;
        if ($stream === null) {
            return SendResult::REASON_DROPPED;
        }
        // 🔒 Anything the server sent after agreeing to encrypt, but before
        // encryption began, was sent in the clear and could have been added by
        // somebody in between. Treating it as part of the encrypted
        // conversation is a known attack on STARTTLS, so it ends the connection.
        if ($this->buffer !== '') {
            return SendResult::REASON_INJECTED_TEXT;
        }
        $deadline = min($deadline, $this->clock->ticks() + $this->config->handshakeSeconds);
        // 🔒 The method comes from the same place as the settings: see `tlsOptions()`.
        // (It used to be written out a second time here. The two could then
        // drift apart, and the test read the one PHP does not obey.)
        $method = (int) self::tlsOptions($this->config, $this->mode, $this->pin)['crypto_method'];
        while (true) {
            // On a non-blocking socket this returns true (done), false (failed)
            // or 0 (not finished yet: wait for the server and ask again).
            $done = $this->quietly(static fn () => stream_socket_enable_crypto($stream, true, $method));
            if ($done === true) {
                break;
            }
            if ($done === false) {
                return $this->whyTheHandshakeFailed();
            }
            $left = $deadline - $this->clock->ticks();
            if ($left <= 0.0) {
                return SendResult::REASON_TIMEOUT;
            }
            $this->wait(min($left, 0.05), false);
        }

        // PHP has accepted the handshake. Check what was agreed ourselves as
        // well, so that a mistake in the settings above cannot pass silently.
        $protocol = stream_get_meta_data($stream)['crypto']['protocol'] ?? '';
        if ($protocol !== 'TLSv1.2' && $protocol !== 'TLSv1.3') {
            $this->phpWords = 'The encryption agreed was not TLS 1.2 or later.';
            return SendResult::REASON_NO_ENCRYPTION;
        }
        $shown = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        $facts = $shown instanceof \OpenSSLCertificate
            ? CertificateFacts::fromCertificate($shown, $this->config->host, $this->clock->now())
            : null;
        if ($facts === null) {
            // Refuse when unsure: a certificate that cannot be read cannot be
            // checked by the rules below, so it is not accepted.
            $this->phpWords = "The server's certificate could not be read.";
            return SendResult::REASON_CERTIFICATE;
        }
        if ($this->mode === self::MODE_ONE_CERTIFICATE && hash_equals($this->pin, $facts->sha256) !== true) {
            $this->phpWords = 'The server showed a different certificate from the one that was approved.';
            $this->refused = $facts->withReason(CertificateFacts::NOT_APPROVED);
            return SendResult::REASON_CERTIFICATE;
        }
        // 🪪 The library's own name rule, on top of PHP's. A certificate must
        // pass BOTH. PHP 8.5.10 accepts a certificate whose list of names is
        // for another server when its old "common name" matches; PHP 8.4.26
        // refuses it; both accept `m*.example.org` and a bare `*`. With this
        // test the answer is the same on every PHP version.
        // (Only the name is re-checked. The dates, the issuer and the key
        // stay OpenSSL's to judge: it sees the whole chain, and this class
        // sees one certificate.)
        if ($this->mode === self::MODE_CHECKED && in_array(CertificateFacts::WRONG_NAME, $facts->reasons, true) === true) {
            $this->phpWords = 'PHP accepted the certificate, but it is not for this server name by the stricter rule of this client.';
            $this->refused = $facts;
            return SendResult::REASON_CERTIFICATE;
        }
        $this->encrypted = true;
        $this->certificate = $facts;
        $this->transcript[] = 'Encryption is on (' . $protocol . '). The certificate is for '
            . ($facts->names === [] ? 'no name' : implode(', ', $facts->names))
            . ', issued by ' . $facts->issuer . ', valid until ' . gmdate('j F Y', $facts->validTo) . ' (UTC).';
        return '';
    }

    /**
     * Sort a handshake PHP has just refused, from PHP's own words about it.
     * See `PHP_BLAMES_THE_CERTIFICATE` for the words and for what happens
     * when they are not recognised.
     */
    private function whyTheHandshakeFailed(): string
    {
        if (preg_match(self::PHP_BLAMES_THE_CERTIFICATE, $this->phpWords) === 1) {
            return SendResult::REASON_CERTIFICATE;
        }
        if (preg_match(self::NO_SHARED_VERSION, $this->phpWords) === 1) {
            return SendResult::REASON_NO_ENCRYPTION;
        }
        return SendResult::REASON_ENCRYPTION_FAILED;
    }

    // -------------------------------------------------------------------------
    // 💬 Commands and replies
    // -------------------------------------------------------------------------

    /**
     * Write one command line and read the reply to it.
     *
     * @return array{problem:string,code:int,text:string,lines:list<string>} See `readReply()`.
     */
    public function command(string $line, float $deadline): array
    {
        return $this->exchange($line, $deadline, $line);
    }

    /**
     * Write one line that carries a password, and read the reply.
     *
     * 🔒 Refused unless encryption is on. This is the one path a password can
     * take to the wire, so the rule "never sign in over a line that is not
     * encrypted" is kept here, whatever the caller does.
     *
     * @param string $shownAs What the transcript shows in place of the line.
     *
     * @return array{problem:string,code:int,text:string,lines:list<string>}
     */
    public function secretCommand(#[\SensitiveParameter] string $line, float $deadline, string $shownAs): array
    {
        if ($this->encrypted !== true) {
            return ['problem' => self::NOT_ALLOWED, 'code' => 0, 'text' => 'No sign-in line is sent before encryption is on.', 'lines' => []];
        }
        return $this->exchange($line, $deadline, $shownAs);
    }

    /**
     * Read one whole reply: one or more lines, each a three-digit number, then
     * "-" (more lines follow) or a space (the last line), then words.
     *
     * 🚦 Anything that is not exactly that is `unexpected-reply`, with the
     * server's words kept. A reply is never guessed at.
     *
     * @return array{problem:string,code:int,text:string,lines:list<string>}
     *         `problem` is empty when a whole reply was read; otherwise `timeout`,
     *         `dropped` or `unexpected-reply`. `text` is the lines joined with " / ".
     */
    public function readReply(float $deadline): array
    {
        $lines = [];
        $code = 0;
        $problem = '';
        while ($problem === '') {
            $raw = $this->takeLine($deadline, $problem);
            if ($raw === null) {
                break;
            }
            // Take the password out FIRST (a server may repeat a sign-in line
            // back), then make the words safe to store and show.
            $shown = CertificateFacts::clean($this->config->scrub($raw), 500);
            $this->transcript[] = 'S: ' . $shown;
            $isReply = preg_match('/\A([2-5][0-9]{2})(?:([ -])(.*))?\z/s', $raw, $parts) === 1;
            if ($isReply !== true || ($code !== 0 && (int) $parts[1] !== $code) || count($lines) >= self::MAX_REPLY_LINES) {
                $lines[] = $shown;
                $problem = SendResult::REASON_UNEXPECTED;
                break;
            }
            $code = (int) $parts[1];
            $lines[] = CertificateFacts::clean($this->config->scrub($parts[3] ?? ''), 500);
            if (($parts[2] ?? ' ') === ' ') {
                return ['problem' => '', 'code' => $code, 'text' => implode(' / ', $lines), 'lines' => $lines];
            }
        }
        return ['problem' => $problem, 'code' => 0, 'text' => implode(' / ', $lines), 'lines' => $lines];
    }

    /**
     * Write a whole message after the server has said "go ahead", followed by
     * the line that marks its end.
     *
     * @return string Empty when every byte was taken by the socket; otherwise `timeout` or `dropped`.
     */
    public function sendBody(string $message, float $deadline): string
    {
        // 🚦 The server has said "go ahead" and should now be listening. If
        // it has already said something more, that is its answer to a message
        // it has not seen: the conversation is out of step and nothing it
        // says next can be trusted. (A server that sent "354" and a stray
        // "250" together, then refused the message, was reported as having
        // accepted it.) Nothing has been written, so nothing was sent.
        if ($this->mayNotWrite() === true) {
            return self::NOT_ALLOWED;
        }
        if ($this->somethingWaiting() === true) {
            $this->noteOutOfTurn();
            return SendResult::REASON_UNEXPECTED;
        }
        $this->transcript[] = 'C: [the message, ' . strlen($message) . ' bytes]';
        $problem = $this->write(self::dotStuff($message) . ".\r\n", $deadline);
        // The same rule at the other end. `write()` only reads while bytes
        // are still waiting to go, so anything it kept arrived BEFORE the
        // message's last line was sent, and cannot be the verdict on it.
        if ($problem === '' && $this->buffer !== '') {
            $this->noteOutOfTurn();
            return self::SPOKE_DURING_MESSAGE;
        }
        return $problem;
    }

    /**
     * Prepare a message for the wire (RFC 5321 section 4.5.2).
     *
     * A line holding only a full stop ends the message. So any line of the
     * message that BEGINS with a full stop is given a second one, which the
     * server removes again. Without that, content containing such a line would
     * end the message early, and whatever followed would be read by the server
     * as new commands: a second, forged message.
     *
     * Line endings are made CR LF first. Some servers also treat a bare LF as
     * a line end, so a message holding "LF . LF" could end early on those even
     * with the doubling. With every line end made the same, there is only one
     * shape to guard.
     */
    public static function dotStuff(string $message): string
    {
        $message = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $message);
        if ($message !== '' && substr($message, -2) !== "\r\n") {
            $message .= "\r\n";
        }
        if ($message !== '' && $message[0] === '.') {
            $message = '.' . $message;
        }
        return str_replace("\r\n.", "\r\n..", $message);
    }

    // -------------------------------------------------------------------------
    // ℹ️ State
    // -------------------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->stream !== null;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /** The certificate the server showed, once encryption is on. */
    public function certificate(): ?CertificateFacts
    {
        return $this->certificate;
    }

    /** The certificate this class refused by its own rule in the last handshake, if that is what happened. */
    public function refusedCertificate(): ?CertificateFacts
    {
        return $this->refused;
    }

    /** PHP's own words about the last failed open or handshake, made safe to store. */
    public function lastWords(): string
    {
        return CertificateFacts::clean($this->config->scrub($this->phpWords), 500);
    }

    /**
     * The conversation so far, and forget it.
     *
     * @return list<string>
     */
    public function takeTranscript(): array
    {
        $lines = $this->transcript;
        $this->transcript = [];
        return $lines;
    }

    /**
     * Close the line.
     *
     * @param bool $sayGoodbye Send QUIT first and give the server two seconds to answer. Only
     *                         worth doing on a connection known to be healthy.
     */
    public function close(bool $sayGoodbye = false): void
    {
        if ($this->stream === null) {
            return;
        }
        if ($sayGoodbye === true && $this->mode !== self::MODE_READ_CERTIFICATE) {
            try {
                $this->exchange('QUIT', $this->clock->ticks() + 2.0, 'QUIT');
            } catch (\Throwable) {
                // The goodbye is a courtesy. A clock handed in by the
                // application may be broken, and closing must still close:
                // an exception let out here would leave the socket open.
            }
        }
        $stream = $this->stream;
        $this->quietly(static fn () => fclose($stream));
        $this->stream = null;
        $this->encrypted = false;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close(false);
    }

    // -------------------------------------------------------------------------
    // ⏱️ The reads and writes themselves
    // -------------------------------------------------------------------------

    /** @return array{problem:string,code:int,text:string,lines:list<string>} */
    private function exchange(#[\SensitiveParameter] string $line, float $deadline, string $shownAs): array
    {
        // 🚫 A command is one line. A line break inside it would be a second
        // command of somebody else's choosing. `Message` already refuses the
        // values these lines are built from; this is the same rule at the last
        // possible moment, so no caller can get round it.
        if (preg_match('/[\r\n\x00]/', $line) === 1) {
            return ['problem' => self::NOT_ALLOWED, 'code' => 0, 'text' => 'A command may not contain a line break.', 'lines' => []];
        }
        // 🚦 The server should be listening now. If it has already said
        // something nobody asked for, the conversation is out of step: its
        // next "reply" would be read as the answer to this command when it is
        // not. So nothing is written, and the caller closes the connection.
        if ($this->mayNotWrite() === true) {
            return ['problem' => self::NOT_ALLOWED, 'code' => 0, 'text' => 'This connection may only read a certificate.', 'lines' => []];
        }
        if ($this->somethingWaiting() === true) {
            return [
                'problem' => SendResult::REASON_UNEXPECTED,
                'code' => 0,
                'text' => 'The server spoke out of turn: ' . $this->noteOutOfTurn(),
                'lines' => [],
            ];
        }
        $this->transcript[] = 'C: ' . $shownAs;
        $problem = $this->write($line . "\r\n", $deadline);
        if ($problem !== '') {
            return ['problem' => $problem, 'code' => 0, 'text' => '', 'lines' => []];
        }
        return $this->readReply($deadline);
    }

    /**
     * Write bytes in pieces against the clock.
     *
     * Two limits apply. `$deadline` is the latest moment the whole write may
     * end. And if the server takes NOTHING for `writeSeconds`, it has stopped
     * reading, and the write is given up without waiting for the deadline.
     *
     * @return string Empty when every byte was taken; otherwise `timeout`, `dropped` or `not-allowed`.
     */
    private function write(#[\SensitiveParameter] string $bytes, float $deadline): string
    {
        if ($this->mayNotWrite() === true) {
            return self::NOT_ALLOWED;
        }
        $stream = $this->stream;
        if ($stream === null) {
            return SendResult::REASON_DROPPED;
        }
        $length = strlen($bytes);
        $sent = 0;
        $lastProgress = $this->clock->ticks();
        $failures = 0;
        while ($sent < $length) {
            $now = $this->clock->ticks();
            if ($now >= $deadline || $now - $lastProgress >= $this->config->writeSeconds) {
                return SendResult::REASON_TIMEOUT;
            }
            $piece = substr($bytes, $sent, self::PIECE);
            $taken = $this->quietly(static fn () => fwrite($stream, $piece));
            unset($piece);
            if (is_int($taken) === true && $taken > 0) {
                $sent += $taken;
                $lastProgress = $this->clock->ticks();
                $failures = 0;
                continue;
            }
            // Nothing was taken. Either the server's side is full (it will say
            // when there is room) or the line is dead.
            if ($taken === false) {
                $failures++;
            }
            if (feof($stream) === true || $failures >= 3) {
                return SendResult::REASON_DROPPED;
            }
            if ($this->wait(min($deadline - $now, self::SLICE), true) === true) {
                // The server has said something, or has hung up. Keep what it
                // said for the next reply; a server may answer early.
                $early = $this->quietly(static fn () => fread($stream, 8192));
                if (is_string($early) === true && $early !== '') {
                    $this->buffer .= $early;
                    if (strlen($this->buffer) > self::MAX_LINE_BYTES * self::MAX_REPLY_LINES) {
                        return SendResult::REASON_DROPPED;
                    }
                } elseif (feof($stream) === true) {
                    return SendResult::REASON_DROPPED;
                }
            }
            // A short pause, so that a socket claiming to have room while
            // taking nothing cannot spin the processor until the limit.
            usleep(2000);
        }
        return '';
    }

    /**
     * 🔒 The rule that makes "read-certificate" mode safe: once encryption is
     * on, that mode writes nothing. Not a sign-in, not a command. Asked by
     * `write()` itself, and before that by its callers, so that a refused
     * write is always reported as exactly that.
     */
    private function mayNotWrite(): bool
    {
        return $this->mode === self::MODE_READ_CERTIFICATE && $this->encrypted === true;
    }

    /**
     * Take one line from what the server has sent, reading more if needed.
     *
     * @param string $problem Set to `timeout`, `dropped` or `unexpected-reply` when null is returned.
     */
    private function takeLine(float $deadline, string &$problem): ?string
    {
        while (true) {
            $end = strpos($this->buffer, "\n");
            if ($end !== false) {
                if ($end > self::MAX_LINE_BYTES) {
                    $problem = SendResult::REASON_UNEXPECTED;
                    return null;
                }
                $line = substr($this->buffer, 0, $end);
                $this->buffer = substr($this->buffer, $end + 1);
                return rtrim($line, "\r");
            }
            if (strlen($this->buffer) > self::MAX_LINE_BYTES) {
                $problem = SendResult::REASON_UNEXPECTED;
                return null;
            }
            $problem = $this->fill($deadline);
            if ($problem !== '') {
                return null;
            }
        }
    }

    /**
     * Has the server ALREADY said something that nobody has read? Looks once
     * and does not wait. Whatever is found is kept for `noteOutOfTurn()`.
     */
    private function somethingWaiting(): bool
    {
        if ($this->buffer !== '') {
            return true;
        }
        $stream = $this->stream;
        if ($stream === null) {
            return false;
        }
        $early = $this->quietly(static fn () => fread($stream, 8192));
        if (is_string($early) === true && $early !== '') {
            $this->buffer .= $early;
            return true;
        }
        return false;
    }

    /** Put what the server said out of turn into the transcript, and return it made safe to show. */
    private function noteOutOfTurn(): string
    {
        $shown = CertificateFacts::clean($this->config->scrub(substr($this->buffer, 0, 300)), 300);
        $this->transcript[] = 'S (out of turn): ' . $shown;
        return $shown;
    }

    /**
     * Wait, up to the deadline, for the server to send something, and add it
     * to the buffer.
     *
     * @return string Empty when something arrived; otherwise `timeout` or `dropped`.
     */
    private function fill(float $deadline): string
    {
        $stream = $this->stream;
        if ($stream === null) {
            return SendResult::REASON_DROPPED;
        }
        $emptyReads = 0;
        while (true) {
            // Read BEFORE waiting. With encryption on, PHP may already hold
            // decrypted bytes that the socket itself no longer shows as
            // waiting; waiting first would sit out the whole limit on them.
            $chunk = $this->quietly(static fn () => fread($stream, 8192));
            if (is_string($chunk) === true && $chunk !== '') {
                $this->buffer .= $chunk;
                return '';
            }
            if (feof($stream) === true) {
                return SendResult::REASON_DROPPED;
            }
            $left = $deadline - $this->clock->ticks();
            if ($left <= 0.0) {
                return SendResult::REASON_TIMEOUT;
            }
            if ($this->wait(min($left, self::SLICE), false) === true && ++$emptyReads > 3) {
                // The socket keeps saying "something is here" while giving
                // nothing (encryption housekeeping can do this briefly).
                usleep(5000);
            }
        }
    }

    /**
     * Sleep until the server has sent something, or (when asked) has room for
     * more, or the time is up.
     *
     * @return bool True when there is something to read.
     */
    private function wait(float $seconds, bool $alsoForRoom): bool
    {
        // With no open socket there is nothing to wait for. (No caller gets
        // here without one today; this makes that true of the function itself,
        // because handing PHP a list holding "no socket" stops with an error.)
        $stream = $this->stream;
        if ($stream === null) {
            return false;
        }
        $read = [$stream];
        $write = $alsoForRoom === true ? [$stream] : null;
        $except = null;
        $microseconds = (int) (max(0.0, min($seconds, self::SLICE)) * 1000000);
        $ready = $this->quietly(static function () use (&$read, &$write, &$except, $microseconds) {
            return stream_select($read, $write, $except, 0, $microseconds);
        });
        return is_int($ready) === true && $ready > 0 && $read !== [];
    }

    /**
     * Run one of PHP's socket functions and catch its warnings.
     *
     * PHP reports the real cause of a failed connection or handshake only as a
     * warning. Caught here, those words can be given to an administrator, and
     * they never reach the screen, the server's error log or the handler of
     * the application using this library. (The `@` sign was tried first: it
     * hides the warning from the screen but still calls the application's own
     * error handler, which may log it.)
     */
    private function quietly(\Closure $call): mixed
    {
        set_error_handler(function (int $level, string $message): bool {
            if (strlen($this->phpWords) < 1000) {
                $this->phpWords .= ($this->phpWords === '' ? '' : ' | ') . $message;
            }
            return true;
        });
        try {
            // Written as a method call on purpose: the stand-alone check
            // allows no call through a variable (`$call()`), because a
            // variable can hold the NAME of any function. This parameter can
            // only be a closure written in this file.
            return $call->__invoke();
        } finally {
            restore_error_handler();
        }
    }
}
