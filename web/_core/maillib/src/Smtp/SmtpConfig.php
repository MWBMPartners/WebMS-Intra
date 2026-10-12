<?php
// Path: web/_core/maillib/src/Smtp/SmtpConfig.php
/**
 * -----------------------------------------------------------------------------
 * SmtpConfig — the settings for one SMTP mailbox ⚙️
 * -----------------------------------------------------------------------------
 * A plain holder for what the SMTP route needs: which server, which port, how
 * the connection is encrypted, the user name and password, and the time
 * limits. The application fills one in from wherever it keeps its settings;
 * this library never reads a setting itself.
 *
 * 🔒 WHAT IS DELIBERATELY NOT HERE
 *   - No "unencrypted" choice. `security` is `starttls` (start in the clear,
 *     then switch encryption on before anything private is said; usually port
 *     587) or `tls` (encrypted from the first byte; usually port 465). Any
 *     other value, `none` included, means "not set up".
 *   - No switch to skip the certificate check.
 *
 * `caFile` is not such a switch. It names a file of certificate authorities to
 * trust INSTEAD of the machine's own list, for a mail server whose certificate
 * comes from an organisation's private authority. The certificate is still
 * checked in full against that file: its dates, its name and its signature.
 *
 * Making one of these never fails, so an application can build it from
 * half-filled settings and then ask `missing()` what is wrong. The route
 * refuses to send while that list is not empty.
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

use MWBM\Mail\Secret;

final class SmtpConfig
{
    public const STARTTLS = 'starttls';
    public const TLS = 'tls';

    /** No time limit may be set longer than this many seconds: a "limit" of an hour is not a limit. */
    private const LONGEST_LIMIT = 300.0;

    /**
     * @param string $host                  The mail server's name as the mail provider gives it. It is also the
     *                                      name the server's certificate must carry.
     * @param int    $port                  1 to 65535.
     * @param string $security              `starttls` or `tls`.
     * @param string $username              The mailbox's user name.
     * @param Secret $password              The mailbox's password, wrapped so it cannot be printed.
     * @param string $caFile                Optional: a file of certificate authorities to trust instead of the
     *                                      machine's own list. Empty for the machine's list.
     * @param string $clientName            The name this client gives when it introduces itself to the server.
     * @param float  $connectSeconds        The longest wait to reach the server.
     * @param float  $handshakeSeconds      The longest wait for encryption to be switched on.
     * @param float  $replySeconds          The longest wait for each ordinary reply.
     * @param float  $writeSeconds          The longest the server may go without taking any of what is being
     *                                      written before the write is given up.
     * @param float  $bodyReplySeconds      The longest wait for the reply after the whole message was handed over.
     * @param float  $messageSeconds        The longest one message may take in all, up to that last reply.
     * @param int    $maxMessageBytes       The biggest message this route will try to send (20 MB). A server that
     *                                      advertises a smaller limit lowers it further.
     * @param int    $messagesPerConnection After this many messages a connection is closed and a new one opened.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $security,
        public readonly string $username,
        public readonly Secret $password,
        public readonly string $caFile = '',
        public readonly string $clientName = 'localhost',
        public readonly float $connectSeconds = 8.0,
        public readonly float $handshakeSeconds = 8.0,
        public readonly float $replySeconds = 10.0,
        public readonly float $writeSeconds = 10.0,
        public readonly float $bodyReplySeconds = 20.0,
        public readonly float $messageSeconds = 30.0,
        public readonly int $maxMessageBytes = 20971520,
        public readonly int $messagesPerConnection = 50,
    ) {
    }

    /**
     * What is missing or wrong, one plain sentence each. An empty list means
     * the settings are complete. It cannot promise the server will accept
     * them: only a send can show that.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $problems = [];
        $name = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        // Called as `->__invoke()` below, not `$isName(...)`: the stand-alone
        // check allows no call through a variable, because a variable can
        // hold the NAME of any function.
        $isName = static fn (string $value): bool =>
            strlen($value) <= 253 && preg_match('/\A' . $name . '(?:\.' . $name . ')*\z/', $value) === 1;

        // The server name is later written into a connection address. Letters,
        // digits, dots and hyphens only, so it can never carry a port, a path
        // or another kind of address in with it.
        if ($isName->__invoke($this->host) !== true) {
            $problems[] = 'The mail server name is missing or is not a server name.';
        }
        if ($this->port < 1 || $this->port > 65535) {
            $problems[] = 'The port must be a number from 1 to 65535.';
        }
        if ($this->security !== self::STARTTLS && $this->security !== self::TLS) {
            $problems[] = 'The connection security must be "starttls" or "tls". There is no unencrypted choice.';
        }
        // A zero byte in the user name or password would split the PLAIN
        // sign-in line into the wrong parts, so neither may hold a control
        // character.
        if ($this->username === '' || preg_match('/[\x00-\x1F\x7F]/', $this->username) === 1) {
            $problems[] = 'The user name is missing or contains a control character.';
        }
        $password = $this->password->reveal();
        if ($password === '') {
            $problems[] = 'No password is stored.';
        } elseif (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            $problems[] = 'The password contains a control character.';
        }
        unset($password);
        if ($this->caFile !== '' && is_readable($this->caFile) !== true) {
            $problems[] = 'The file of trusted certificate authorities cannot be read.';
        }
        if ($isName->__invoke($this->clientName) !== true) {
            $problems[] = "The client's own name is not a server name.";
        }
        $limits = [
            $this->connectSeconds, $this->handshakeSeconds, $this->replySeconds,
            $this->writeSeconds, $this->bodyReplySeconds, $this->messageSeconds,
        ];
        foreach ($limits as $limit) {
            if (($limit > 0.0 && $limit <= self::LONGEST_LIMIT) !== true) {
                $problems[] = 'Every time limit must be more than 0 and at most 300 seconds.';
                break;
            }
        }
        if ($this->maxMessageBytes < 1 || $this->messagesPerConnection < 1) {
            $problems[] = 'The largest message size and the messages per connection must each be at least 1.';
        }
        return $problems;
    }

    /**
     * Remove the password, in every form the sign-in sends it, from text that
     * came from the server. See `Secret::scrub()` for why and how.
     */
    public function scrub(string $text): string
    {
        return $this->password->scrub($text, "\0" . $this->username . "\0");
    }
}
