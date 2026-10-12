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
 * @package   MWBM\Mail\Smtp
 * @author    MWBM Partners Ltd (t/a MWservices)
 * @copyright 2026-present MWBM Partners Ltd (t/a MWservices)
 * @license   All Rights Reserved
 * @version   0.1.0
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace MWBM\Mail\Smtp;

use MWBM\Mail\CertificateFacts;
use MWBM\Mail\Clock;

final class CertificateReader
{
    /** @var list<string> What was said on the reading connection, for a "send a test" page. */
    private array $transcript = [];

    public function __construct(private readonly SmtpConfig $config, private readonly Clock $clock)
    {
    }

    /**
     * Connect, read the certificate, hang up. The whole of it is held to the
     * connection limit plus the handshake limit.
     */
    public function read(): ?CertificateFacts
    {
        $connection = new Connection($this->config, $this->clock, Connection::MODE_READ_CERTIFICATE);
        $deadline = $this->clock->ticks() + $this->config->connectSeconds + $this->config->handshakeSeconds;
        try {
            if ($connection->open($deadline) !== '') {
                return null;
            }
            if ($this->config->security === SmtpConfig::STARTTLS) {
                // The same three steps the real connection takes before
                // encryption. All of it is public; none of it is private.
                $greeting = $connection->readReply($this->replyBy($deadline));
                if ($greeting['problem'] !== '' || $greeting['code'] !== 220) {
                    return null;
                }
                $hello = $connection->command('EHLO ' . $this->config->clientName, $this->replyBy($deadline));
                if ($hello['problem'] !== '' || $hello['code'] !== 250) {
                    return null;
                }
                $start = $connection->command('STARTTLS', $this->replyBy($deadline));
                if ($start['problem'] !== '' || $start['code'] !== 220) {
                    return null;
                }
            }
            if ($connection->startEncryption($deadline) !== '') {
                return null;
            }
            return $connection->certificate();
        } finally {
            $this->transcript = $connection->takeTranscript();
            $connection->close(false);
        }
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
