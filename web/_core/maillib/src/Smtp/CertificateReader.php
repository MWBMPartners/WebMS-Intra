<?php
// Path: web/_core/maillib/src/Smtp/CertificateReader.php
/**
 * -----------------------------------------------------------------------------
 * CertificateReader — find out WHY a server's certificate was refused 🔍
 * -----------------------------------------------------------------------------
 * When the checked connection fails, PHP gives the same words for every cause
 * ("certificate verify failed"). To tell an administrator which it was, this
 * class connects ONE more time with the check switched off, reads the
 * certificate the server shows, and hangs up.
 *
 * 🔒 WHY THAT IS SAFE. An unchecked connection could be to an impostor, so
 * nothing private may ever be said on it. It is opened in `Connection`'s
 * "read-certificate" mode, which refuses every write once encryption is on.
 * Before encryption, on a STARTTLS server, it says only what any stranger
 * says: its name, and "please start encryption". It is never given a sign-in
 * line, an address or a message, and could not send one if it were.
 *
 * WHAT IT RETURNS. The certificate's facts, with the specific faults it shows
 * (expired, not valid yet, another name, signed by itself). Or nothing at all,
 * when even an unchecked encrypted connection cannot be made. In that case the
 * certificate is not the problem: the server offers no encryption this library
 * accepts, and there is no certificate for anybody to approve.
 *
 * WHAT IT CANNOT DO. It sees the certificate shown at THAT moment. A server
 * (or an impostor) could show a different one a second later. That is why an
 * approval names the certificate by its fingerprint, and the sending
 * connection accepts only that fingerprint.
 *
 * 🩺 A SECOND QUESTION, ADDED 13 OCTOBER 2026: `wouldPass()`. "Would this
 * server's certificate pass the FULL check right now?" It is for an
 * application whose administrator has switched checking off, or accepted one
 * certificate: asked now and then, it is how such an application finds out
 * that the certificate has been put right and checking can go back on. It
 * makes one fully checked connection as far as the handshake and hangs up.
 * It never signs in and never names an address, so it says nothing private
 * either, although that connection COULD write: the safety here is that this
 * class has no line that does.
 *
 * @package   MWBM\Mail\Smtp
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.2.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail\Smtp;

use MWBM\Mail\CertificateFacts;
use MWBM\Mail\Clock;
use MWBM\Mail\SendResult;

final class CertificateReader
{
    /** @var list<string> What was said on the reading connection, for a "send a test" page. */
    private array $transcript = [];

    /** Where the last read or check stopped when it did not finish: a `SendResult::STAGE_` name. Empty when it finished. */
    private string $stoppedAt = '';

    /** Why it stopped there: a `SendResult::REASON_` name. Empty when it finished. */
    private string $stoppedWhy = '';

    /** PHP's own words about the step that failed, made safe to store. */
    private string $stoppedWords = '';

    public function __construct(private readonly SmtpConfig $config, private readonly Clock $clock)
    {
    }

    /**
     * Connect, read the certificate, hang up. The whole of it is held to the
     * connection limit plus the handshake limit.
     *
     * Null when no encrypted connection could be made at all; `stoppedAt()`
     * and `stoppedWhy()` then say where and why.
     */
    public function read(): ?CertificateFacts
    {
        $connection = new Connection($this->config, $this->clock, Connection::MODE_READ_CERTIFICATE);
        try {
            return $this->asFarAsEncryption($connection) === true ? $connection->certificate() : null;
        } finally {
            $this->transcript = $connection->takeTranscript();
            $connection->close(false);
        }
    }

    /**
     * 🩺 Would the certificate pass the full check now? See the file header.
     *
     * @return bool|null True: it passed, by PHP's check and by this library's own name rule.
     *                   False: it was refused, and it was the certificate that was at fault.
     *                   Null: it could not be told (the server could not be reached, offered no
     *                   encryption, or the handshake failed in a way that is not the
     *                   certificate's doing). A caller must treat null as "nothing has changed".
     */
    public function wouldPass(): ?bool
    {
        $connection = new Connection($this->config, $this->clock, Connection::MODE_CHECKED);
        try {
            if ($this->asFarAsEncryption($connection) === true) {
                return true;
            }
            return $this->stoppedWhy === SendResult::REASON_CERTIFICATE ? false : null;
        } finally {
            $this->transcript = $connection->takeTranscript();
            // No goodbye: saying QUIT would be a line written after
            // encryption, and this class writes none.
            $connection->close(false);
        }
    }

    /** Where the last read or check stopped: a `SendResult::STAGE_` name, or empty when it finished. */
    public function stoppedAt(): string
    {
        return $this->stoppedAt;
    }

    /** Why it stopped: a `SendResult::REASON_` name, or empty when it finished. */
    public function stoppedWhy(): string
    {
        return $this->stoppedWhy;
    }

    /** PHP's own words about the step that failed, or empty. */
    public function stoppedWords(): string
    {
        return $this->stoppedWords;
    }

    /**
     * Open the line and switch encryption on, under the connection's own
     * certificate rule. Everything said before encryption is what any
     * stranger says to a mail server: its name, and "please encrypt".
     *
     * WHAT IT DOES NOT DO: look at whether the server LISTED encryption
     * before asking for it. A sending connection does, and stops when it is
     * not listed (somebody in the middle can strip the offer out). Here the
     * question is only about the certificate, and asking says nothing
     * private. So `wouldPass()` can answer "yes" for a server a send would
     * still stop at; the send then says why.
     *
     * @return bool True when encryption is on. Otherwise the three "stopped" fields say where and why.
     */
    private function asFarAsEncryption(Connection $connection): bool
    {
        $this->stoppedAt = '';
        $this->stoppedWhy = '';
        $this->stoppedWords = '';
        $deadline = $this->clock->ticks() + $this->config->connectSeconds + $this->config->handshakeSeconds;
        $problem = $connection->open($deadline);
        if ($problem !== '') {
            return $this->stopped(SendResult::STAGE_CONNECT, $problem, $connection);
        }
        if ($this->config->security === SmtpConfig::STARTTLS) {
            // The same three steps the real connection takes before
            // encryption. All of it is public; none of it is private.
            $greeting = $connection->readReply($this->replyBy($deadline));
            if ($greeting['problem'] !== '' || $greeting['code'] !== 220) {
                return $this->stopped(SendResult::STAGE_GREETING, $greeting['problem'] !== '' ? $greeting['problem'] : SendResult::REASON_UNEXPECTED, $connection);
            }
            $hello = $connection->command('EHLO ' . $this->config->clientName, $this->replyBy($deadline));
            if ($hello['problem'] !== '' || $hello['code'] !== 250) {
                return $this->stopped(SendResult::STAGE_EHLO, $hello['problem'] !== '' ? $hello['problem'] : SendResult::REASON_UNEXPECTED, $connection);
            }
            $start = $connection->command('STARTTLS', $this->replyBy($deadline));
            if ($start['problem'] !== '' || $start['code'] !== 220) {
                // The server did not agree to encrypt. Whatever number it
                // gave, the meaning for the caller is one: no encryption.
                return $this->stopped(SendResult::STAGE_STARTTLS, $start['problem'] !== '' ? $start['problem'] : SendResult::REASON_NO_ENCRYPTION, $connection);
            }
        }
        $problem = $connection->startEncryption($deadline);
        if ($problem !== '') {
            return $this->stopped(SendResult::STAGE_TLS, $problem, $connection);
        }
        return true;
    }

    /** Note where and why it stopped. Always false, so a caller can return it. */
    private function stopped(string $stage, string $reason, Connection $connection): bool
    {
        $this->stoppedAt = $stage;
        $this->stoppedWhy = $reason === Connection::NOT_ALLOWED ? SendResult::REASON_INTERNAL : $reason;
        $this->stoppedWords = $connection->lastWords();
        return false;
    }

    /**
     * What was said while reading, each line marked as belonging to this second connection.
     *
     * @return list<string>
     */
    public function transcript(): array
    {
        return array_map(static fn (string $line): string => '(reading the certificate) ' . $line, $this->transcript);
    }

    private function replyBy(float $deadline): float
    {
        return min($deadline, $this->clock->ticks() + $this->config->replySeconds);
    }
}
